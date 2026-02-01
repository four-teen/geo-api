<?php

namespace App\Http\Controllers\Api\Admin\LivestockCharges;

use App\Http\Controllers\Api\Helpers\BaseController;
use App\Http\Controllers\Controller;
use App\Models\LivestockCharges; // ← Import the correct model
use Illuminate\Http\Request;

class LivestockChargesController extends BaseController
{
    // GET all
    public function index()
    {
        $charges = LivestockCharges::all(); // ← Fix class name
        return response()->json([
            'status' => true,
            'data' => $charges
        ]);
    }

    // GET one
    public function show($id)
    {
        $charge = LivestockCharges::find($id); // ← Fix class name
        if (!$charge) {
            return response()->json(['status' => false, 'message' => 'Not found'], 404);
        }
        return response()->json(['status' => true, 'data' => $charge]);
    }

    // CREATE
    public function store(Request $request)
    {
        $validated = $request->validate([
            'livestock_id' => 'required|integer',
            'cf' => 'required|numeric|min:0',
            'sf' => 'required|numeric|min:0',
            'spf' => 'required|numeric|min:0',
            'pmf' => 'required|numeric|min:0',
        ]);

        $charge = LivestockCharges::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Livestock charge created successfully',
            'data' => $charge
        ], 201);
    }

    // UPDATE
    public function update(Request $request, $id)
    {
        $charge = LivestockCharges::find($id);
        if (!$charge) {
            return response()->json(['status' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'livestock_id' => 'required|integer',
            'cf' => 'required|numeric|min:0',
            'sf' => 'required|numeric|min:0',
            'spf' => 'required|numeric|min:0',
            'pmf' => 'required|numeric|min:0',
        ]);

        $charge->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Livestock charge updated successfully',
            'data' => $charge
        ]);
    }

    // DELETE
    public function destroy($id)
    {
        $charge = LivestockCharges::find($id);
        if (!$charge) {
            return response()->json(['status' => false, 'message' => 'Not found'], 404);
        }

        $charge->delete();

        return response()->json([
            'status' => true,
            'message' => 'Livestock charge deleted successfully'
        ]);
    }
}
