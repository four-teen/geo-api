<?php

namespace App\Http\Controllers\Api\Admin\SlaughterPrivate;

use App\Http\Controllers\Api\Helpers\BaseController;
use Illuminate\Http\Request;
use App\Models\SlaughterPrivate;
use Illuminate\Support\Facades\Validator;


class SLPrivate extends BaseController
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date'   => 'required|date',
            'or_no'  => 'required|unique:slaughter_privates,or_no',
            'agency' => 'required|string',
            'owner'  => 'required|string',
            'small_heads' => 'nullable|integer|min:0',
            'large_heads' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        $transaction = SlaughterPrivate::create($request->all());

        return response()->json([
            'success' => true,
            'data'    => $transaction,
            'message' => 'Private transaction created successfully.'
        ]);
    }

    public function index()
    {
        $transactions = SlaughterPrivate::orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data'    => $transactions
        ]);
    }

    public function update(Request $request, $id)
    {
        $transaction = SlaughterPrivate::find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'date'   => 'required|date',
            'or_no'  => 'required|unique:slaughter_privates,or_no,' . $id,
            'agency' => 'required|string',
            'owner'  => 'required|string',
            'small_heads' => 'nullable|integer|min:0',
            'large_heads' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        $transaction->update($request->all());

        return response()->json([
            'success' => true,
            'data'    => $transaction,
            'message' => 'Transaction updated successfully.'
        ]);
    }

    public function destroy($id)
    {
        $transaction = SlaughterPrivate::find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.'
            ], 404);
        }

        $transaction->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transaction deleted successfully.'
        ]);
    }


}
