<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
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
public function getByPatient($patient_id)
{
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
            'p.remarks',
            'p.created_at',
            'ph.first_name',
            'ph.middle_name',
            'ph.last_name'
        )
        ->orderBy('p.date_released', 'DESC')
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
            'p.date_released',
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
        'physician_id' => 'required|exists:bow_tbl_physicians,physician_id',
        'remarks' => 'nullable|string',

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
        'diagnosis' => 'nullable|string',
    ]);

    foreach ($request->items as $idx => $item) {
        $medicineId = $item['medicine_id'] ?? null;
        $unavailableName = trim($item['unavailable_medicine_name'] ?? '');

        if (!$medicineId && $unavailableName === '') {
            throw ValidationException::withMessages([
                "items.$idx.medicine_id" => "Select medicine or enter unavailable medicine name.",
            ]);
        }
    }

    return \DB::transaction(function () use ($request) {

        // 1. Create prescription header
        $prescriptionId = $this->nextId('bow_tbl_prescriptions', 'prescription_id');

\DB::table('bow_tbl_prescriptions')->insert([
    'prescription_id'   => $prescriptionId,
    'patient_id'       => $request->patient_id,
    'physician_id'     => $request->physician_id,

    'blood_pressure'   => $request->blood_pressure,
    'heart_rate'       => $request->heart_rate,
    'body_temperature' => $request->body_temperature,
    'height_cm'        => $request->height_cm,
    'weight_kg'        => $request->weight_kg,

    'diagnosis'        => $request->diagnosis,
    'remarks'          => $request->remarks,

    'date_released'    => now(),
    'created_at'       => now(),
    'updated_at'       => now(),
]);



        // 2. Process items + deduct inventory
        $nextItemId = $this->nextId('bow_tbl_prescription_items', 'item_id');

        foreach ($request->items as $item) {
            $medicineId = $item['medicine_id'] ?? null;
            $unavailableName = trim($item['unavailable_medicine_name'] ?? '');

            if ($medicineId) {
                $medicine = \DB::table('bow_tbl_medicines')
                    ->where('medicine_id', $medicineId)
                    ->lockForUpdate()
                    ->first();

                if (!$medicine) {
                    throw new \Exception('Medicine not found.');
                }

                if ($medicine->quantity < $item['qty']) {
                    throw new \Exception(
                        "Insufficient stock for {$medicine->medicine_name}"
                    );
                }

                // Insert prescription item (inventory medicine)
                \DB::table('bow_tbl_prescription_items')->insert([
                    'item_id' => $nextItemId++,
                    'prescription_id' => $prescriptionId,
                    'medicine_id' => $medicineId,
                    'qty' => $item['qty'],
                    'direction' => $item['direction'],
                    'good_for_days' => $item['good_for_days'],
                    'created_at' => now(),
                ]);

                // Deduct inventory
                \DB::table('bow_tbl_medicines')
                    ->where('medicine_id', $medicineId)
                    ->update([
                        'quantity' => $medicine->quantity - $item['qty'],
                        'updated_at' => now(),
                    ]);
            } else {
                // Resolve / create medicine master record (inactive) for unavailable entry
                $manualMedicine = \DB::table('bow_tbl_medicines')->whereRaw(
                    'LOWER(medicine_name) = ?',
                    [mb_strtolower($unavailableName)]
                )->first();

                if (!$manualMedicine) {
                    $manualMedicineId = $this->nextId('bow_tbl_medicines', 'medicine_id');

                    \DB::table('bow_tbl_medicines')->insert([
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
                \DB::table('bow_tbl_prescription_items')->insert([
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
    });
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
public function show($prescription_id)
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
            'p.date_released',

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
 * Generate next integer PK for legacy tables without AUTO_INCREMENT.
 */
private function nextId(string $table, string $idColumn): int
{
    $maxId = \DB::table($table)->max($idColumn);

    return ((int) $maxId) + 1;
}


}
