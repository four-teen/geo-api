<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        'items.*.medicine_id' => 'required|exists:bow_tbl_medicines,medicine_id',
        'items.*.qty' => 'required|numeric|min:1',
        'items.*.direction' => 'required|string',
        'items.*.good_for_days' => 'required|integer|min:1',
    ]);

    return \DB::transaction(function () use ($request) {

        // 1. Create prescription header
        $prescriptionId = \DB::table('bow_tbl_prescriptions')->insertGetId([
            'patient_id' => $request->patient_id,
            'physician_id' => $request->physician_id,
            'remarks' => $request->remarks,
            'date_released' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Process items + deduct inventory
        foreach ($request->items as $item) {

            $medicine = \DB::table('bow_tbl_medicines')
                ->where('medicine_id', $item['medicine_id'])
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

            // Insert prescription item
            \DB::table('bow_tbl_prescription_items')->insert([
                'prescription_id' => $prescriptionId,
                'medicine_id' => $item['medicine_id'],
                'qty' => $item['qty'],
                'direction' => $item['direction'],
                'good_for_days' => $item['good_for_days'],
                'created_at' => now(),
            ]);

            // Deduct inventory
            \DB::table('bow_tbl_medicines')
                ->where('medicine_id', $item['medicine_id'])
                ->update([
                    'quantity' => $medicine->quantity - $item['qty'],
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Prescription saved successfully.',
        ]);
    });
}



}
