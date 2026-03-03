<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowPrecinct;
use App\Models\BowPurok;
use App\Support\BowScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrecinctController extends Controller
{
    public function getByPurok(Request $request, int $purokId)
    {
        $purok = BowPurok::query()->findOrFail($purokId);
        BowScope::ensureBarangayAccess($request->user(), (int) $purok->barangay_id);

        $data = BowPrecinct::query()
            ->where('purok_id', $purokId)
            ->orderBy('precinct_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'purok_id' => ['required', 'integer', 'exists:bow_tbl_puroks,purok_id'],
            'precinct_name' => ['required', 'string', 'max:150'],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $purok = BowPurok::query()->findOrFail((int) $validated['purok_id']);
        BowScope::ensureBarangayAccess($request->user(), (int) $purok->barangay_id);

        $exists = BowPrecinct::query()
            ->where('purok_id', $validated['purok_id'])
            ->where('precinct_name', $validated['precinct_name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Precinct already exists in this purok.',
            ], 422);
        }

        BowPrecinct::query()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Precinct created successfully.',
        ]);
    }

    public function update(Request $request, int $id)
    {
        $precinct = BowPrecinct::query()->findOrFail($id);
        $purok = BowPurok::query()->findOrFail((int) $precinct->purok_id);
        BowScope::ensureBarangayAccess($request->user(), (int) $purok->barangay_id);

        $validated = $request->validate([
            'precinct_name' => ['required', 'string', 'max:150'],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $duplicate = BowPrecinct::query()
            ->where('purok_id', $precinct->purok_id)
            ->where('precinct_name', $validated['precinct_name'])
            ->where('precinct_id', '<>', $precinct->precinct_id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Precinct already exists in this purok.',
            ], 422);
        }

        $precinct->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Precinct updated successfully.',
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $precinct = BowPrecinct::query()->findOrFail($id);
        $purok = BowPurok::query()->findOrFail((int) $precinct->purok_id);
        BowScope::ensureBarangayAccess($request->user(), (int) $purok->barangay_id);

        $precinct->delete();

        return response()->json([
            'success' => true,
            'message' => 'Precinct deleted successfully.',
        ]);
    }
}
