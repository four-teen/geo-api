<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – BARANGAY CONTROLLER
 * ----------------------------------------------------------------------------
 * Endpoint Prefix : /api/bow/barangay
 * Methods:
 * - index   : list barangays
 * - store   : create barangay
 * - update  : update barangay
 * - destroy : delete barangay
 * ============================================================================
 */

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowBarangay;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BarangayController extends Controller
{
    /**
     * Display a listing of barangays.
     */
    public function index()
    {
        $data = BowBarangay::orderBy('barangay_name')->get();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * Store a newly created barangay.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'barangay_name' => 'required|string|max:150|unique:bow_tbl_barangays,barangay_name',
            'status'        => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        BowBarangay::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Barangay created successfully.',
        ]);
    }

    /**
     * Update the specified barangay.
     */
    public function update(Request $request, $id)
    {
        $barangay = BowBarangay::findOrFail($id);

        $validated = $request->validate([
            'barangay_name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('bow_tbl_barangays', 'barangay_name')->ignore($barangay->barangay_id, 'barangay_id'),
            ],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $barangay->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Barangay updated successfully.',
        ]);
    }

    /**
     * Remove the specified barangay.
     */
    public function destroy($id)
    {
        $barangay = BowBarangay::findOrFail($id);
        $barangay->delete();

        return response()->json([
            'success' => true,
            'message' => 'Barangay deleted successfully.',
        ]);
    }
}
