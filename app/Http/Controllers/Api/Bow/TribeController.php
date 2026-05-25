<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowRecipient;
use App\Models\BowTribe;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TribeController extends Controller
{
    public function index()
    {
        $data = BowTribe::query()
            ->withCount('recipients')
            ->orderBy('tribe_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tribe_name' => ['required', 'string', 'max:150', 'unique:bow_tbl_tribes,tribe_name'],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        BowTribe::query()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tribe created successfully.',
        ]);
    }

    public function update(Request $request, int $id)
    {
        $tribe = BowTribe::query()->findOrFail($id);

        $validated = $request->validate([
            'tribe_name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('bow_tbl_tribes', 'tribe_name')->ignore($tribe->tribe_id, 'tribe_id'),
            ],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $tribe->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tribe updated successfully.',
        ]);
    }

    public function destroy(int $id)
    {
        $tribe = BowTribe::query()->findOrFail($id);
        $isInUse = BowRecipient::query()
            ->where('tribe_id', $tribe->tribe_id)
            ->exists();

        if ($isInUse) {
            return response()->json([
                'success' => false,
                'message' => 'This tribe is assigned to voters and cannot be deleted.',
            ], 422);
        }

        $tribe->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tribe deleted successfully.',
        ]);
    }
}
