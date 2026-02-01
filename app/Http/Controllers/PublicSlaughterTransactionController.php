<?php

/**
 * ============================================================
 * CONTROLLER: PublicSlaughterTransactionController
 * ------------------------------------------------------------
 * File : app/Http/Controllers/PublicSlaughterTransactionController.php
 *
 * Purpose :
 * - Handles PUBLIC slaughter transactions
 * - APP 1 (Cashier): create initial transaction
 * - APP 2 (Slaughter): will update later
 *
 * Design Rules :
 * - ONE table, ONE model
 * - Status-driven workflow
 * - No silent overwrites
 * - No guessing of fields
 * ============================================================
 */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use App\Models\SlaughterPublicTransaction;

class PublicSlaughterTransactionController extends Controller
{
    /**
     * ============================================================
     * CREATE BY CASHIER (APP 1)
     * ------------------------------------------------------------
     * Route :
     * POST /api/public-slaughter/cashier/create
     *
     * Responsibilities :
     * - Validate cashier input
     * - Create a NEW public slaughter transaction
     * - Set status = cashier_only
     *
     * Notes :
     * - Does NOT handle PMF or kilos
     * - Does NOT complete the transaction
     * ============================================================
     */
    public function createByCashier(Request $request)
    {
        /**
         * --------------------------------------------------------
         * 1️⃣ VALIDATION (NO GUESSING)
         * --------------------------------------------------------
         */
        $validator = Validator::make($request->all(), [
            'or_number'     => 'required|string|max:50',
            'agency'        => 'required|string|max:150',
            'payor'         => 'required|string|max:150',

            'small_heads'   => 'nullable|integer|min:0',
            'goat_heads'    => 'nullable|integer|min:0',
            'hog_heads'     => 'nullable|integer|min:0',

            'large_heads'   => 'nullable|integer|min:0',
            'cow_heads'     => 'nullable|integer|min:0',
            'carabao_heads' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        /**
         * --------------------------------------------------------
         * 2️⃣ ENSURE AT LEAST ONE HEAD IS PRESENT
         * --------------------------------------------------------
         */
        $smallHeads = (int) ($request->small_heads ?? 0);
        $largeHeads = (int) ($request->large_heads ?? 0);

        if ($smallHeads === 0 && $largeHeads === 0) {
            return response()->json([
                'message' => 'At least one head (small or large) is required.',
            ], 422);
        }

        /**
         * --------------------------------------------------------
         * 3️⃣ CREATE TRANSACTION (SAFE FIELDS ONLY)
         * --------------------------------------------------------
         */
        $transaction = SlaughterPublicTransaction::create([
            // Cashier info
            'or_number'           => $request->or_number,
            'agency'              => $request->agency,
            'payor'               => $request->payor,
            'cashier_user_id'     => $request->user()->id ?? null,
            'cashier_encoded_at'  => Carbon::now(),

            // Head counts
            'small_heads'         => $smallHeads,
            'goat_heads'          => (int) ($request->goat_heads ?? 0),
            'hog_heads'           => (int) ($request->hog_heads ?? 0),

            'large_heads'         => $largeHeads,
            'cow_heads'           => (int) ($request->cow_heads ?? 0),
            'carabao_heads'       => (int) ($request->carabao_heads ?? 0),

            // Workflow
            'status'              => SlaughterPublicTransaction::STATUS_CASHIER_ONLY,
        ]);

        /**
         * --------------------------------------------------------
         * 4️⃣ RESPONSE
         * --------------------------------------------------------
         */
        return response()->json([
            'message' => 'Public cashier transaction saved successfully.',
            'data'    => [
                'id'     => $transaction->id,
                'status' => $transaction->status,
            ],
        ], 201);
    }

    /**
     * ============================================================
     * LOOKUP PENDING PUBLIC TRANSACTIONS
     * ------------------------------------------------------------
     * Purpose :
     * - Used by Lookup screen
     * - Returns transactions that still need processing
     *
     * Rules :
     * - Excludes completed records
     * - Sorted latest first
     * ============================================================
     */
    public function lookupPending()
    {
        $records = \App\Models\SlaughterPublicTransaction::query()
            ->where('status', '!=', \App\Models\SlaughterPublicTransaction::STATUS_COMPLETED)
            ->orderBy('created_at', 'desc')
            ->get([
                'id',
                'or_number',
                'agency',
                'payor',
                'small_heads',
                'large_heads',
                'status',
                'created_at',
            ]);

        return response()->json([
            'message' => 'Pending public slaughter transactions',
            'data'    => $records,
        ]);
    }

    /**
     * ============================================================
     * SHOW PUBLIC SLAUGHTER TRANSACTION (BY ID)
     * ------------------------------------------------------------
     * Purpose :
     * - Fetch a SINGLE public transaction
     * - Used by App 2 (Public Slaughter)
     *
     * Rules :
     * - Must exist
     * - Must NOT be completed
     * ============================================================
     */
    public function show($id)
    {
        $transaction = \App\Models\SlaughterPublicTransaction::find($id);

        if (!$transaction) {
            return response()->json([
                'message' => 'Transaction not found',
            ], 404);
        }

        if ($transaction->status === \App\Models\SlaughterPublicTransaction::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'Transaction already completed',
            ], 409);
        }

        return response()->json([
            'message' => 'Public transaction loaded',
            'data'    => $transaction,
        ]);
    }

/**
 * ============================================================
 * UPDATE PUBLIC SLAUGHTER TRANSACTION (APP 2)
 * ------------------------------------------------------------
 * Route :
 * PUT /api/public-slaughter/slaughter/update/{id}
 *
 * Purpose :
 * - Complete slaughter-side encoding
 * - Updates ONLY slaughter-related fields
 * - Status transition handled explicitly
 *
 * Rules :
 * - ID-based update ONLY
 * - No guessing
 * - Cashier data must NOT be overwritten
 * ============================================================
 */
public function updateBySlaughter(Request $request, int $id)
{
    try {
        $transaction = SlaughterPublicTransaction::findOrFail($id);

        /**
         * --------------------------------------------------------
         * SLAUGHTER PAYLOAD (EXPLICIT)
         * --------------------------------------------------------
         */
        $transaction->update([

            // Heads
            'small_heads'   => (int) $request->input('small_heads', 0),
            'goat_heads'    => (int) $request->input('goat_heads', 0),
            'hog_heads'     => (int) $request->input('hog_heads', 0),

            'large_heads'   => (int) $request->input('large_heads', 0),
            'cow_heads'     => (int) $request->input('cow_heads', 0),
            'carabao_heads' => (int) $request->input('carabao_heads', 0),

            // Kilos
            'small_kilos'   => (float) $request->input('small_kilos', 0),
            'large_kilos'   => (float) $request->input('large_kilos', 0),

            // PMF
            'pmf_amount'    => (float) $request->input('pmf_amount', 0),

            // Metadata
            'slaughter_user_id' => auth()->id(),
            'slaughter_encoded_at' => now(),
        ]);

        /**
         * --------------------------------------------------------
         * STATUS TRANSITION
         * --------------------------------------------------------
         */
        if ($transaction->status === SlaughterPublicTransaction::STATUS_CASHIER_ONLY) {
            $transaction->status = SlaughterPublicTransaction::STATUS_COMPLETED;
        } else {
            $transaction->status = SlaughterPublicTransaction::STATUS_SLAUGHTER_ONLY;
        }

        $transaction->save();

        return response()->json([
            'message' => 'Public slaughter transaction updated successfully',
            'data'    => $transaction,
        ], 200);

    } catch (\Exception $e) {

        return response()->json([
            'message' => 'Failed to update public slaughter transaction',
            'error'   => $e->getMessage(),
        ], 500);
    }
}


}
