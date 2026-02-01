<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – PHYSICIAN CONTROLLER
 * ----------------------------------------------------------------------------
 * Endpoint Prefix : /api/bow/physician
 * Pattern         : same style as PatientController
 *
 * Methods:
 * - index : list physicians
 * - store : create physician
 *
 * Notes:
 * - No status column (as agreed)
 * - License/mobile not required, not unique (as agreed)
 * ============================================================================
 */

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowPhysician;
use Illuminate\Http\Request;

class PhysicianController extends Controller
{
    /**
     * List physicians.
     */
    public function index()
    {
        $data = BowPhysician::orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * Create a physician record.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'     => 'required|string|max:100',
            'middle_name'    => 'nullable|string|max:100',
            'last_name'      => 'required|string|max:100',

            'license_number' => 'nullable|string|max:100',
            'mobile_number'  => 'nullable|string|max:30',
            'address'        => 'nullable|string',
        ]);

        BowPhysician::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Physician created successfully.',
        ]);
    }
    /**
     * Update a physician record.
     */
    public function update(Request $request, $id)
    {
        $physician = BowPhysician::findOrFail($id);

        $validated = $request->validate([
            'first_name'     => 'required|string|max:100',
            'middle_name'    => 'nullable|string|max:100',
            'last_name'      => 'required|string|max:100',

            'license_number' => 'nullable|string|max:100',
            'mobile_number'  => 'nullable|string|max:30',
            'address'        => 'nullable|string',
        ]);

        $physician->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Physician updated successfully.',
        ]);
    }

    /**
     * Delete a physician record.
     */
    public function destroy($id)
    {
        $physician = BowPhysician::findOrFail($id);
        $physician->delete();

        return response()->json([
            'success' => true,
            'message' => 'Physician deleted successfully.',
        ]);
    }


}

