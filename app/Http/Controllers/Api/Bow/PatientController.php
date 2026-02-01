<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – PATIENT CONTROLLER
 * ----------------------------------------------------------------------------
 * Endpoint Prefix : /api/bow/patient
 * Pattern         : same as Barangay & Purok
 *
 * Methods:
 * - index   : list patients (optional filters)
 * - store   : create patient
 * - update  : update patient
 * - destroy : delete patient
 *
 * Extra:
 * - getByBarangay : list patients by barangay_id (explicit route)
 * ============================================================================
 */

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowPatient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    /**
     * List patients (supports optional filters via query params).
     * Supported query params:
     * - barangay_id
     * - purok_id
     * - status
     */
    public function index(Request $request)
    {
        $q = BowPatient::query();

        if ($request->filled('barangay_id')) {
            $q->where('barangay_id', $request->barangay_id);
        }

        if ($request->filled('purok_id')) {
            $q->where('purok_id', $request->purok_id);
        }

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        $data = $q->orderBy('last_name')
                  ->orderBy('first_name')
                  ->get();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

/**
 * ============================================================================
 * Get Patients by Barangay (WITH PUROK NAME)
 * ----------------------------------------------------------------------------
 * Purpose:
 * - Return patients filtered by barangay
 * - Include purok_name via JOIN
 * - Used by Patients module list
 * ============================================================================
 */
public function getByBarangay($barangay_id)
{
    $patients = \DB::table('bow_tbl_patients as p')
        ->leftJoin('bow_tbl_puroks as pr', 'p.purok_id', '=', 'pr.purok_id')
        ->select(
            'p.patient_id',
            'p.last_name',
            'p.first_name',
            'p.middle_name',
            'p.birthdate',
            'p.sex',
            'p.marital_status',
            'p.spouse_name',
            'p.contact_number',
            'p.is_pwd',
            'p.is_senior',
            'p.status',
            'p.purok_id',
            'pr.purok_name'
        )
        ->where('p.barangay_id', $barangay_id)
        ->orderBy('p.last_name')
        ->get();

    return response()->json($patients);
}


    /**
     * Create a patient record.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'last_name'       => 'required|string|max:100',
            'first_name'      => 'required|string|max:100',
            'middle_name'     => 'nullable|string|max:100',

            'birthdate'       => 'required|date',
            'sex'             => ['required', Rule::in(['M', 'F'])],

            'marital_status'  => ['nullable', Rule::in(['SINGLE', 'MARRIED', 'WIDOWED', 'SEPARATED'])],
            'spouse_name'     => 'nullable|string|max:150',

            'is_pwd'          => 'required|boolean',
            'is_senior'       => 'nullable|boolean',
            'contact_number'  => 'nullable|string|max:30',

            'barangay_id'     => 'required|exists:bow_tbl_barangays,barangay_id',
            'purok_id'        => 'required|exists:bow_tbl_puroks,purok_id',

            'status'          => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        BowPatient::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Patient created successfully.',
        ]);
    }

    /**
     * Update a patient record.
     */
    public function update(Request $request, $id)
    {
        $patient = BowPatient::findOrFail($id);

        /**
         * ============================================================
         * STATUS-ONLY UPDATE (ACTIVE / INACTIVE)
         * ------------------------------------------------------------
         * Purpose:
         * - Allow status toggle without requiring full payload
         * - Used by Patients list "Set Active / Set Inactive" button
         * ============================================================
         */
        if ($request->has('status') && $request->keys() === ['status']) {
            $request->validate([
                'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
            ]);

            $patient->update([
                'status' => $request->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Patient status updated successfully.',
            ]);
        }


        $validated = $request->validate([
            'last_name'       => 'required|string|max:100',
            'first_name'      => 'required|string|max:100',
            'middle_name'     => 'nullable|string|max:100',

            'birthdate'       => 'required|date',
            'sex'             => ['required', Rule::in(['M', 'F'])],

            'marital_status'  => ['nullable', Rule::in(['SINGLE', 'MARRIED', 'WIDOWED', 'SEPARATED'])],
            'spouse_name'     => 'nullable|string|max:150',

            'is_pwd'          => 'required|boolean',
            'is_senior'       => 'nullable|boolean',
            'contact_number'  => 'nullable|string|max:30',

            'barangay_id'     => 'required|exists:bow_tbl_barangays,barangay_id',
            'purok_id'        => 'required|exists:bow_tbl_puroks,purok_id',

            'status'          => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $patient->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Patient updated successfully.',
        ]);
    }

    /**
     * Delete a patient record.
     */
    public function destroy($id)
    {
        $patient = BowPatient::findOrFail($id);
        $patient->delete();

        return response()->json([
            'success' => true,
            'message' => 'Patient deleted successfully.',
        ]);
    }
}
