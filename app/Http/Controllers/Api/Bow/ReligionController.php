<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowRecipient;
use App\Models\BowReligion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReligionController extends Controller
{
    public function index()
    {
        $data = BowReligion::query()
            ->withCount('recipients')
            ->orderBy('religion_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'religion_name' => ['required', 'string', 'max:150', 'unique:bow_tbl_religions,religion_name'],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        BowReligion::query()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Religion created successfully.',
        ]);
    }

    public function update(Request $request, int $id)
    {
        $religion = BowReligion::query()->findOrFail($id);
        $oldName = (string) $religion->religion_name;

        $validated = $request->validate([
            'religion_name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('bow_tbl_religions', 'religion_name')->ignore($religion->religion_id, 'religion_id'),
            ],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        DB::transaction(function () use ($religion, $oldName, $validated) {
            $religion->update($validated);

            if ($oldName !== $validated['religion_name']) {
                BowRecipient::query()
                    ->where('religion', $oldName)
                    ->update(['religion' => $validated['religion_name']]);
            }
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Religion updated successfully.',
        ]);
    }

    public function destroy(int $id)
    {
        $religion = BowReligion::query()->findOrFail($id);
        $isInUse = BowRecipient::query()
            ->where('religion', $religion->religion_name)
            ->exists();

        if ($isInUse) {
            return response()->json([
                'success' => false,
                'message' => 'This religion is assigned to voters and cannot be deleted.',
            ], 422);
        }

        $religion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Religion deleted successfully.',
        ]);
    }
}
