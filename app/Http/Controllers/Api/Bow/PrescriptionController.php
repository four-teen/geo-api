<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Support\BowScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ============================================================================
 * BOTIKA ON WHEELS – PRESCRIPTION CONTROLLER
 * ----------------------------------------------------------------------------
 * PURPOSE:
 * - Handles prescription-related API endpoints
 * - Step 2: Fetch prescription HISTORY per patient
 *
 * RULES:
 * - READ-ONLY (history only)
 * - Sorted latest to oldest
 * - No inventory logic here
 * ============================================================================
 */
class PrescriptionController extends Controller
{
/**
 * ============================================================
 * GET PRESCRIPTION HISTORY BY PATIENT (WITH MEDICINE SUMMARY)
 * ------------------------------------------------------------
 * Route : GET bow/prescription/by-patient/{patient_id}
 * Access: Authenticated (Sanctum)
 *
 * Returns:
 * - One row per prescription
 * - Physician full name
 * - Aggregated medicines list
 * - Total quantity released
 * - Latest first
 * ============================================================
 */
public function getByPatient(Request $request, $patient_id)
{
    $patient = DB::table('bow_tbl_patients')
        ->select('patient_id', 'barangay_id')
        ->where('patient_id', $patient_id)
        ->first();

    if (!$patient) {
        return response()->json([
            'status' => 'error',
            'message' => 'Patient not found.'
        ], 404);
    }

    BowScope::ensureBarangayAccess($request->user(), (int) $patient->barangay_id);

    $releaseStatusExpr = "CASE
        WHEN UPPER(COALESCE(p.release_status, '')) = 'RELEASED'
            OR p.released_at IS NOT NULL
        THEN 'RELEASED'
        ELSE 'PENDING'
    END";

    $history = DB::table('bow_tbl_prescriptions as p')
        ->join(
            'bow_tbl_physicians as ph',
            'ph.physician_id',
            '=',
            'p.physician_id'
        )
        ->leftJoin(
            'bow_tbl_prescription_items as pi',
            'p.prescription_id',
            '=',
            'pi.prescription_id'
        )
        ->leftJoin(
            'bow_tbl_medicines as m',
            'pi.medicine_id',
            '=',
            'm.medicine_id'
        )
        ->where('p.patient_id', $patient_id)
        ->groupBy(
            'p.prescription_id',
            'p.patient_id',
            'p.physician_id',
            'p.date_released',
            'p.released_at',
            'p.release_status',
            'p.remarks',
            'p.created_at',
            'ph.first_name',
            'ph.middle_name',
            'ph.last_name'
        )
        ->orderBy(DB::raw('COALESCE(p.released_at, p.date_released, p.created_at)'), 'DESC')
        ->select(
            'p.prescription_id',
            'p.patient_id',
            'p.physician_id',
            DB::raw(
                "CONCAT(
                    ph.first_name, ' ',
                    IF(ph.middle_name IS NOT NULL AND ph.middle_name != '',
                        CONCAT(LEFT(ph.middle_name,1), '. '),
                        ''
                    ),
                    ph.last_name
                ) as physician_name"
            ),
            DB::raw('COALESCE(p.released_at, p.date_released, p.created_at) as date_released'),
            DB::raw("{$releaseStatusExpr} as release_status"),
            'p.remarks',
            'p.created_at',
            DB::raw("
                GROUP_CONCAT(
                    CONCAT(m.medicine_name, ' (', pi.qty, ')')
                    SEPARATOR ', '
                ) as medicines
            "),
            DB::raw("SUM(pi.qty) as total_qty")
        )
        ->get();

    $user = $request->user();
    $isAdministrator = $user
        && method_exists($user, 'isAdministrator')
        && $user->isAdministrator();

    $history = $history->map(function ($row) use ($isAdministrator) {
        $row->can_delete = $isAdministrator
            && strtoupper((string) ($row->release_status ?? '')) === 'PENDING';

        return $row;
    });

    return response()->json([
        'status' => 'success',
        'data'   => $history
    ]);
}


/**
 * ============================================================================
 * STORE PRESCRIPTION (WITH INVENTORY DEDUCTION)
 * ----------------------------------------------------------------------------
 * Rules:
 * - Uses DB transaction
 * - Deducts medicine stock safely
 * - Rolls back on ANY error
 * ============================================================================
 */
public function store(Request $request)
{
    $request->validate([
        'patient_id' => 'required|exists:bow_tbl_patients,patient_id',
        'physician_id' => 'required|integer|exists:bow_tbl_physicians,physician_id',
        'remarks' => 'required|string',
        'diagnosis' => 'required|string',

        'items' => 'required|array|min:1',
        'items.*.medicine_id' => 'nullable|integer|exists:bow_tbl_medicines,medicine_id',
        'items.*.unavailable_medicine_name' => 'nullable|string|max:255',
        'items.*.qty' => 'required|numeric|min:1',
        'items.*.direction' => 'required|string',
        'items.*.good_for_days' => 'required|integer|min:1',
        'blood_pressure' => 'nullable|string',
        'heart_rate' => 'nullable|numeric',
        'body_temperature' => 'nullable|numeric',
        'height_cm' => 'nullable|numeric',
        'weight_kg' => 'nullable|numeric',
    ]);

    foreach ($request->items as $idx => $item) {
        $medicineId = isset($item['medicine_id']) ? (int) $item['medicine_id'] : null;
        $unavailableName = trim($item['unavailable_medicine_name'] ?? '');

        if (($medicineId === null || $medicineId <= 0) && $unavailableName === '') {
            throw ValidationException::withMessages([
                "items.$idx.medicine_id" => "Select medicine or enter unavailable medicine name.",
            ]);
        }
    }

    $patient = DB::table('bow_tbl_patients')
        ->select('patient_id', 'barangay_id')
        ->where('patient_id', $request->patient_id)
        ->first();

    if (!$patient) {
        return response()->json([
            'success' => false,
            'message' => 'Patient not found.',
        ], 404);
    }

    BowScope::ensureBarangayAccess($request->user(), (int) $patient->barangay_id);

    return DB::transaction(function () use ($request) {

        // 1. Create prescription header
        $prescriptionId = $this->nextId('bow_tbl_prescriptions', 'prescription_id');

        DB::table('bow_tbl_prescriptions')->insert([
            'prescription_id' => $prescriptionId,
            'patient_id' => $request->patient_id,
            'physician_id' => $request->physician_id,

            'blood_pressure' => $request->blood_pressure,
            'heart_rate' => $request->heart_rate,
            'body_temperature' => $request->body_temperature,
            'height_cm' => $request->height_cm,
            'weight_kg' => $request->weight_kg,

            'diagnosis' => $request->diagnosis,
            'remarks' => $request->remarks,

            'date_released' => now(),
            'release_status' => 'PENDING',
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);



        // 2. Process items + deduct inventory
        $nextItemId = $this->nextId('bow_tbl_prescription_items', 'item_id');

        foreach ($request->items as $item) {
            $medicineId = $item['medicine_id'] ?? null;
            $unavailableName = trim($item['unavailable_medicine_name'] ?? '');

            if ($medicineId) {
                DB::table('bow_tbl_prescription_items')->insert([
                    'item_id' => $nextItemId++,
                    'prescription_id' => $prescriptionId,
                    'medicine_id' => $medicineId,
                    'qty' => $item['qty'],
                    'direction' => $item['direction'],
                    'good_for_days' => $item['good_for_days'],
                    'created_at' => now(),
                ]);
            } else {
                // Resolve / create medicine master record (inactive) for unavailable entry
                $manualMedicine = DB::table('bow_tbl_medicines')->whereRaw(
                    'LOWER(medicine_name) = ?',
                    [mb_strtolower($unavailableName)]
                )->first();

                if (!$manualMedicine) {
                    $manualMedicineId = $this->nextId('bow_tbl_medicines', 'medicine_id');

                    DB::table('bow_tbl_medicines')->insert([
                        'medicine_id' => $manualMedicineId,
                        'medicine_name' => $unavailableName,
                        'quantity' => 0,
                        'status' => 'inactive',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $manualMedicine = (object) [
                        'medicine_id' => $manualMedicineId,
                        'medicine_name' => $unavailableName,
                    ];
                }

                // Insert prescription item using resolved medicine_id
                DB::table('bow_tbl_prescription_items')->insert([
                    'item_id' => $nextItemId++,
                    'prescription_id' => $prescriptionId,
                    'medicine_id' => $manualMedicine->medicine_id,
                    'qty' => $item['qty'],
                    'direction' => $item['direction'],
                    'good_for_days' => $item['good_for_days'],
                    'created_at' => now(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Prescription saved successfully.',
        ]);
    }, 3);
}


/**
 * ============================================================
 * GET FULL PRESCRIPTION DETAILS (HEADER + ITEMS)
 * ------------------------------------------------------------
 * Route : GET bow/prescription/{prescription_id}
 * Access: Authenticated (Sanctum)
 *
 * Returns:
 * - prescription header (vitals + diagnosis + remarks + date)
 * - physician full name
 * - patient full name + barangay + purok
 * - items list with medicine_name, qty, direction, good_for_days
 * ============================================================
 */
public function show(Request $request, $prescription_id)
{
    // Header + joins for patient/physician/barangay/purok
    $header = DB::table('bow_tbl_prescriptions as p')
        ->join('bow_tbl_patients as pt', 'pt.patient_id', '=', 'p.patient_id')
        ->leftJoin('bow_tbl_barangays as b', 'b.barangay_id', '=', 'pt.barangay_id')
        ->leftJoin('bow_tbl_puroks as pr', 'pr.purok_id', '=', 'pt.purok_id')
        ->join('bow_tbl_physicians as ph', 'ph.physician_id', '=', 'p.physician_id')
        ->where('p.prescription_id', $prescription_id)
        ->select(
            'p.prescription_id',
            'p.patient_id',
            'p.physician_id',
            'pt.barangay_id as patient_barangay_id',
            'p.date_released',
            'p.released_at',
            DB::raw("CASE
                WHEN UPPER(COALESCE(p.release_status, '')) = 'RELEASED'
                    OR p.released_at IS NOT NULL
                THEN 'RELEASED'
                ELSE 'PENDING'
            END as release_status"),

            'p.blood_pressure',
            'p.heart_rate',
            'p.body_temperature',
            'p.height_cm',
            'p.weight_kg',

            'p.diagnosis',
            'p.remarks',

            DB::raw("CONCAT(
                pt.first_name, ' ',
                IF(pt.middle_name IS NOT NULL AND pt.middle_name != '',
                    CONCAT(pt.middle_name, ' '),
                    ''
                ),
                pt.last_name
            ) as patient_name"),

            DB::raw("CONCAT(
                ph.first_name, ' ',
                IF(ph.middle_name IS NOT NULL AND ph.middle_name != '',
                    CONCAT(LEFT(ph.middle_name,1), '. '),
                    ''
                ),
                ph.last_name
            ) as physician_name"),

            'b.barangay_name',
            'pr.purok_name'
        )
        ->first();

    if (!$header) {
        return response()->json([
            'status' => 'error',
            'message' => 'Prescription not found.'
        ], 404);
    }

    BowScope::ensureBarangayAccess($request->user(), (int) $header->patient_barangay_id);

    // Items
    $items = DB::table('bow_tbl_prescription_items as pi')
        ->leftJoin('bow_tbl_medicines as m', 'm.medicine_id', '=', 'pi.medicine_id')
        ->where('pi.prescription_id', $prescription_id)
        ->select(
            'pi.item_id',
            'pi.medicine_id',
            'm.medicine_name',
            'pi.qty',
            'pi.direction',
            'pi.good_for_days'
        )
        ->orderBy('pi.item_id', 'ASC')
        ->get();

    return response()->json([
        'status' => 'success',
        'data' => [
            'header' => $header,
            'items'  => $items,
        ]
    ]);
}

/**
 * ============================================================
 * DELETE PRESCRIPTION (ADMIN ONLY, PENDING ONLY)
 * ------------------------------------------------------------
 * Route : DELETE bow/prescription/{prescription_id}
 * ============================================================
 */
public function destroy(Request $request, $prescription_id)
{
    return DB::transaction(function () use ($request, $prescription_id) {
        $prescription = DB::table('bow_tbl_prescriptions as p')
            ->join('bow_tbl_patients as pt', 'pt.patient_id', '=', 'p.patient_id')
            ->where('p.prescription_id', $prescription_id)
            ->select(
                'p.prescription_id',
                'p.release_status',
                'p.released_at',
                'pt.barangay_id'
            )
            ->lockForUpdate()
            ->first();

        if (!$prescription) {
            return response()->json([
                'success' => false,
                'message' => 'Prescription not found.',
            ], 404);
        }

        BowScope::ensureBarangayAccess($request->user(), (int) $prescription->barangay_id);

        $alreadyReleased = strtoupper((string) ($prescription->release_status ?? '')) === 'RELEASED'
            || !empty($prescription->released_at);

        if ($alreadyReleased) {
            return response()->json([
                'success' => false,
                'message' => 'Released prescriptions cannot be deleted.',
            ], 422);
        }

        DB::table('bow_tbl_prescription_items')
            ->where('prescription_id', $prescription_id)
            ->delete();

        DB::table('bow_tbl_prescriptions')
            ->where('prescription_id', $prescription_id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Prescription deleted successfully.',
        ]);
    });
}

/**
 * ============================================================
 * RELEASE PRESCRIPTION (DEDUCT INVENTORY ON RELEASE)
 * ------------------------------------------------------------
 * Route : PATCH bow/prescription/{prescription_id}/release
 * ============================================================
 */
public function release(Request $request, $prescription_id)
{
    return DB::transaction(function () use ($request, $prescription_id) {
        $prescription = DB::table('bow_tbl_prescriptions as p')
            ->join('bow_tbl_patients as pt', 'pt.patient_id', '=', 'p.patient_id')
            ->where('p.prescription_id', $prescription_id)
            ->select(
                'p.prescription_id',
                'p.release_status',
                'p.released_at',
                'pt.barangay_id'
            )
            ->lockForUpdate()
            ->first();

        if (!$prescription) {
            return response()->json([
                'success' => false,
                'message' => 'Prescription not found.',
            ], 404);
        }

        BowScope::ensureBarangayAccess($request->user(), (int) $prescription->barangay_id);

        $alreadyReleased = strtoupper((string) ($prescription->release_status ?? '')) === 'RELEASED'
            || !empty($prescription->released_at);

        if ($alreadyReleased) {
            // Normalize mismatched legacy rows (released_at exists but release_status is not RELEASED)
            if (strtoupper((string) ($prescription->release_status ?? '')) !== 'RELEASED') {
                DB::table('bow_tbl_prescriptions')
                    ->where('prescription_id', $prescription_id)
                    ->update([
                        'release_status' => 'RELEASED',
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Prescription already released. No stock was deducted.',
                'already_released' => true,
                'stock_deducted' => false,
            ]);
        }

        $items = DB::table('bow_tbl_prescription_items')
            ->where('prescription_id', $prescription_id)
            ->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Prescription has no medicine items to release.',
            ]);
        }

        foreach ($items as $index => $item) {
            $medicine = DB::table('bow_tbl_medicines')
                ->where('medicine_id', $item->medicine_id)
                ->lockForUpdate()
                ->first();

            if (!$medicine) {
                $position = $index + 1;
                throw ValidationException::withMessages([
                    "items.$index.medicine_id" => "Medicine for item #{$position} was not found.",
                ]);
            }

            if (strtolower((string) $medicine->status) !== 'active') {
                continue;
            }

            if ((float) $medicine->quantity < (float) $item->qty) {
                throw ValidationException::withMessages([
                    "items.$index.qty" => "Insufficient stock for {$medicine->medicine_name}.",
                ]);
            }

            DB::table('bow_tbl_medicines')
                ->where('medicine_id', $medicine->medicine_id)
                ->update([
                    'quantity' => (float) $medicine->quantity - (float) $item->qty,
                    'updated_at' => now(),
                ]);
        }

        $now = now();

        DB::table('bow_tbl_prescriptions')
            ->where('prescription_id', $prescription_id)
            ->update([
                'release_status' => 'RELEASED',
                'released_at' => $now,
                'date_released' => $now,
                'updated_at' => $now,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Prescription released successfully.',
            'already_released' => false,
            'stock_deducted' => true,
        ]);
    });
}


/**
 * Generate next integer PK for legacy tables without AUTO_INCREMENT.
 */
private function nextId(string $table, string $idColumn): int
{
    $maxId = DB::table($table)
        ->selectRaw("COALESCE(MAX({$idColumn}), 0) as max_id")
        ->lockForUpdate()
        ->value('max_id');

    return ((int) $maxId) + 1;
}


}
