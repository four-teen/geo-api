<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – PUROK CONTROLLER
 * ----------------------------------------------------------------------------
 * Endpoint Prefix : /api/bow/purok
 * Methods:
 * - index            : optional (not used)
 * - store            : create purok
 * - update           : update purok
 * - destroy          : delete purok
 * - getByBarangay    : list puroks by barangay
 * ============================================================================
 */

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowPurok;
use App\Support\BowScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurokController extends Controller
{
    /**
     * Get puroks by barangay.
     */
    public function getByBarangay(Request $request, $barangay_id)
    {
        BowScope::ensureBarangayAccess($request->user(), (int) $barangay_id);

        $data = BowPurok::where('barangay_id', $barangay_id)
            ->orderBy('purok_name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * Store a newly created purok.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'barangay_id' => 'required|exists:bow_tbl_barangays,barangay_id',
            'purok_name'  => 'required|string|max:150',
            'status'      => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        BowScope::ensureBarangayAccess($request->user(), (int) $validated['barangay_id']);

        // prevent duplicate purok per barangay
        $exists = BowPurok::where('barangay_id', $validated['barangay_id'])
            ->where('purok_name', $validated['purok_name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Purok already exists in this barangay.',
            ], 422);
        }

        BowPurok::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Purok created successfully.',
        ]);
    }

    /**
     * Update the specified purok.
     */
    public function update(Request $request, $id)
    {
        $purok = BowPurok::findOrFail($id);
        BowScope::ensureBarangayAccess($request->user(), (int) $purok->barangay_id);

        $validated = $request->validate([
            'purok_name' => 'required|string|max:150',
            'status'     => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $purok->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Purok updated successfully.',
        ]);
    }

    /**
     * Remove the specified purok.
     */
    public function destroy(Request $request, $id)
    {
        $purok = BowPurok::findOrFail($id);
        BowScope::ensureBarangayAccess($request->user(), (int) $purok->barangay_id);
        $purok->delete();

        return response()->json([
            'success' => true,
            'message' => 'Purok deleted successfully.',
        ]);
    }
}
