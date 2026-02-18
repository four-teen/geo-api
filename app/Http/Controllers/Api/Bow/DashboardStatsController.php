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
use App\Support\BowScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardStatsController extends Controller
{
    /**
     * GET /api/bow/dashboard/community-health
     */
    public function communityHealthSnapshot(Request $request): JsonResponse
    {
        $basePatientQuery = DB::table('bow_tbl_patients')
            ->where('status', 'ACTIVE');

        BowScope::applyBarangayFilter($basePatientQuery, $request->user());

        return response()->json([
            'male' => (clone $basePatientQuery)
                ->where('sex', 'M')
                ->count(),

            'female' => (clone $basePatientQuery)
                ->where('sex', 'F')
                ->count(),

            'senior' => (clone $basePatientQuery)
                ->where('is_senior', 1)
                ->count(),

            'pwd' => (clone $basePatientQuery)
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
    public function topCardCounts(Request $request): JsonResponse
    {
        $barangaysQuery = DB::table('bow_tbl_barangays')
            ->where('status', 'ACTIVE');

        $patientsQuery = DB::table('bow_tbl_patients')
            ->where('status', 'ACTIVE');

        BowScope::applyBarangayFilter($barangaysQuery, $request->user(), 'barangay_id');
        BowScope::applyBarangayFilter($patientsQuery, $request->user(), 'barangay_id');

        $barangays = $barangaysQuery->count();
        $patients = $patientsQuery->count();

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

    /**
     * ------------------------------------------------------------------------
     * GET PATIENT COUNTS PER BARANGAY
     * ------------------------------------------------------------------------
     * Purpose:
     * - Dashboard chart dataset
     * - Includes ACTIVE barangays, with ACTIVE patient counts
     * ------------------------------------------------------------------------
     */
    public function patientsPerBarangay(Request $request): JsonResponse
    {
        $rowsQuery = DB::table('bow_tbl_barangays as b')
            ->leftJoin('bow_tbl_patients as p', function ($join) {
                $join->on('p.barangay_id', '=', 'b.barangay_id')
                    ->where('p.status', 'ACTIVE');
            })
            ->where('b.status', 'ACTIVE')
            ->groupBy('b.barangay_id', 'b.barangay_name')
            ->orderBy('b.barangay_name', 'ASC')
            ->select(
                'b.barangay_id',
                'b.barangay_name',
                DB::raw('COUNT(p.patient_id) as patient_count')
            );

        BowScope::applyBarangayFilter($rowsQuery, $request->user(), 'b.barangay_id');

        $rows = $rowsQuery->get();

        return response()->json([
            'data' => $rows,
        ]);
    }

    /**
     * ------------------------------------------------------------------------
     * GET MEDICINE RELEASE TRANSACTIONS PER MONTH
     * ------------------------------------------------------------------------
     * Purpose:
     * - Midwife dashboard line chart
     * - Counts medicine release transactions (prescription item rows)
     * - Current year, grouped by month
     * ------------------------------------------------------------------------
     */
    public function medicineReleaseTransactions(Request $request): JsonResponse
    {
        $currentYear = (int) now()->year;

        $rowsQuery = DB::table('bow_tbl_prescriptions as p')
            ->join('bow_tbl_patients as pt', 'pt.patient_id', '=', 'p.patient_id')
            ->join('bow_tbl_prescription_items as pi', 'pi.prescription_id', '=', 'p.prescription_id')
            ->whereYear('p.date_released', $currentYear)
            ->groupBy(DB::raw('MONTH(p.date_released)'))
            ->orderBy(DB::raw('MONTH(p.date_released)'))
            ->selectRaw('MONTH(p.date_released) as month_number')
            ->selectRaw('COUNT(pi.item_id) as transaction_count');

        BowScope::applyBarangayFilter($rowsQuery, $request->user(), 'pt.barangay_id');

        $rows = $rowsQuery->get()->keyBy(fn ($row) => (int) $row->month_number);

        $monthLabels = [
            1 => 'JAN',
            2 => 'FEB',
            3 => 'MAR',
            4 => 'APR',
            5 => 'MAY',
            6 => 'JUN',
            7 => 'JUL',
            8 => 'AUG',
            9 => 'SEP',
            10 => 'OCT',
            11 => 'NOV',
            12 => 'DEC',
        ];

        $data = collect(range(1, 12))->map(function ($month) use ($monthLabels, $rows) {
            $count = isset($rows[$month]) ? (int) $rows[$month]->transaction_count : 0;

            return [
                'month_number' => $month,
                'month_label' => $monthLabels[$month],
                'transaction_count' => $count,
            ];
        })->values();

        return response()->json([
            'year' => $currentYear,
            'data' => $data,
        ]);
    }



}
