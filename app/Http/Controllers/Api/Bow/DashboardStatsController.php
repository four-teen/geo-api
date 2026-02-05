<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – DASHBOARD STATS CONTROLLER
 * ----------------------------------------------------------------------------
 * File        : DashboardStatsController.php
 * Location    : /app/Http/Controllers/Api/Bow/
 * Purpose     :
 *   - Provides READ-ONLY statistical counts for the dashboard
 *   - STRICTLY for counting purposes only
 * ============================================================================
 */

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardStatsController extends Controller
{
    /**
     * GET /api/bow/dashboard/community-health
     */
    public function communityHealthSnapshot(): JsonResponse
    {
        return response()->json([
            'male' => DB::table('bow_tbl_patients')
                ->where('status', 'ACTIVE')
                ->where('sex', 'M')
                ->count(),

            'female' => DB::table('bow_tbl_patients')
                ->where('status', 'ACTIVE')
                ->where('sex', 'F')
                ->count(),

            'senior' => DB::table('bow_tbl_patients')
                ->where('status', 'ACTIVE')
                ->where('is_senior', 1)
                ->count(),

            'pwd' => DB::table('bow_tbl_patients')
                ->where('status', 'ACTIVE')
                ->where('is_pwd', 1)
                ->count(),
        ]);
    }

    /**
     * ------------------------------------------------------------------------
     * GET TOP NAVIGATION CARD COUNTS
     * ------------------------------------------------------------------------
     * Purpose:
     * - Dashboard upper cards count display (right-side numbers)
     *
     * Rules:
     * - READ ONLY
     * - ACTIVE only (where status exists)
     * - No joins
     * ------------------------------------------------------------------------
     */
    public function topCardCounts(): JsonResponse
    {
        $barangays = DB::table('bow_tbl_barangays')
            ->where('status', 'ACTIVE')
            ->count();

        $patients = DB::table('bow_tbl_patients')
            ->where('status', 'ACTIVE')
            ->count();

        // NOTE: bow_tbl_physicians has NO status column (based on your schema)
        // So we count ALL physicians.
        $physicians = DB::table('bow_tbl_physicians')->count();

        $medicines = DB::table('bow_tbl_medicines')
            ->where('status', 'active') // matches schema enum: active/inactive
            ->count();

        return response()->json([
            'barangays'  => $barangays,
            'patients'   => $patients,
            'physicians' => $physicians,
            'medicines'  => $medicines,
        ]);
    }



}
