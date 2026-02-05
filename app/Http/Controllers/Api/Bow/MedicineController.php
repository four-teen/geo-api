<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowMedicine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MedicineController extends Controller
{
    /* ===============================================================
       GET ALL MEDICINES
       =============================================================== */
    public function index()
    {
        $medicines = BowMedicine::orderBy('medicine_name')->get();

        return response()->json([
            'data' => $medicines
        ], 200);
    }

    /* ===============================================================
       STORE MEDICINE
       =============================================================== */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'medicine_name' => 'required|string|max:255|unique:bow_tbl_medicines,medicine_name',
            'quantity'      => 'required|numeric|min:0',
        ]);

        $medicine = BowMedicine::create([
            'medicine_name' => $validated['medicine_name'],
            'quantity'      => $validated['quantity'],
            'status'        => 'active',
        ]);

        return response()->json([
            'message' => 'Medicine created successfully.',
            'data'    => $medicine
        ], 201);
    }

    /* ===============================================================
       UPDATE MEDICINE
       =============================================================== */
    public function update(Request $request, $id)
    {
        $medicine = BowMedicine::findOrFail($id);

        $validated = $request->validate([
            'medicine_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('bow_tbl_medicines', 'medicine_name')
                    ->ignore($medicine->medicine_id, 'medicine_id'),
            ],
            'quantity' => 'required|numeric|min:0',
        ]);

        $medicine->update($validated);

        return response()->json([
            'message' => 'Medicine updated successfully.',
            'data'    => $medicine
        ], 200);
    }

    /* ===============================================================
       TOGGLE STATUS
       =============================================================== */
    public function toggleStatus($id)
    {
        $medicine = BowMedicine::findOrFail($id);

        $medicine->status = $medicine->status === 'active'
            ? 'inactive'
            : 'active';

        $medicine->save();

        return response()->json([
            'message' => 'Medicine status updated.',
            'status'  => $medicine->status
        ], 200);
    }
}
