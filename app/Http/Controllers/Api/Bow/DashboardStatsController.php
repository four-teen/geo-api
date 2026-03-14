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
    public function voterInsights(Request $request): JsonResponse
    {
        $baseQuery = DB::table('bow_tbl_recipients as r');
        BowScope::applyBarangayFilter($baseQuery, $request->user(), 'r.barangay');

        $totalVoters = (clone $baseQuery)->count();
        $assignedBarangay = (clone $baseQuery)->whereNotNull('r.barangay')->count();
        $assignedPurok = (clone $baseQuery)->whereNotNull('r.purok')->count();

        $occupationTotals = $this->buildOccupationTotals(clone $baseQuery);
        $occupationTagged = array_sum($occupationTotals);
        arsort($occupationTotals);

        $topProfessions = collect($occupationTotals)
            ->map(fn (int $total, string $label) => [
                'label' => $label,
                'total' => $total,
                'share' => $totalVoters > 0 ? round(($total / $totalVoters) * 100, 1) : 0.0,
            ])
            ->values();

        $leadingProfession = $topProfessions->first();

        $ageRows = $this->buildAgeEvaluationRows(clone $baseQuery);
        $topBarangays = $this->buildTopBarangays(clone $baseQuery, $totalVoters);

        return response()->json([
            'success' => true,
            'snapshot' => [
                'total_voters' => $totalVoters,
                'occupation_tagged' => $occupationTagged,
                'occupation_coverage' => $totalVoters > 0 ? round(($occupationTagged / $totalVoters) * 100, 1) : 0.0,
                'unique_professions' => count($occupationTotals),
                'assigned_barangay' => $assignedBarangay,
                'assigned_purok' => $assignedPurok,
                'leading_profession' => $leadingProfession ?? [
                    'label' => 'No profession data',
                    'total' => 0,
                    'share' => 0.0,
                ],
            ],
            'profession_snapshot' => $topProfessions->take(4)->values(),
            'top_professions' => $topProfessions->take(8)->values(),
            'top_barangays' => $topBarangays,
            'evaluation_chart' => [
                'categories' => $ageRows->pluck('age_group')->values(),
                'series' => [
                    [
                        'name' => 'All Voters',
                        'data' => $ageRows->pluck('voters')->map(fn ($value) => (int) $value)->values(),
                    ],
                    [
                        'name' => 'Occupation Tagged',
                        'data' => $ageRows->pluck('occupation_tagged')->map(fn ($value) => (int) $value)->values(),
                    ],
                    [
                        'name' => 'Barangay Tagged',
                        'data' => $ageRows->pluck('assigned_barangay')->map(fn ($value) => (int) $value)->values(),
                    ],
                    [
                        'name' => 'Purok Tagged',
                        'data' => $ageRows->pluck('assigned_purok')->map(fn ($value) => (int) $value)->values(),
                    ],
                ],
            ],
        ]);
    }

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

            'hpn' => (clone $basePatientQuery)
                ->where('is_hpn', 1)
                ->count(),

            'dm' => (clone $basePatientQuery)
                ->where('is_dm', 1)
                ->count(),

            'ekonsulta_member' => (clone $basePatientQuery)
                ->where('is_ekonsulta_member', 1)
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
     * GET MONTHLY PATIENT VS PRESCRIPTION TRENDS
     * ------------------------------------------------------------------------
     * Purpose:
     * - Dashboard line chart dataset
     * - Monthly active patient registrations vs released prescriptions
     * - Current year only
     * ------------------------------------------------------------------------
     */
    public function patientPrescriptionTrends(Request $request): JsonResponse
    {
        $currentYear = (int) now()->year;

        $patientsRowsQuery = DB::table('bow_tbl_patients as pt')
            ->where('pt.status', 'ACTIVE')
            ->whereYear('pt.created_at', $currentYear)
            ->groupBy(DB::raw('MONTH(pt.created_at)'))
            ->orderBy(DB::raw('MONTH(pt.created_at)'))
            ->selectRaw('MONTH(pt.created_at) as month_number')
            ->selectRaw('COUNT(pt.patient_id) as patient_count')
            ->selectRaw("SUM(CASE WHEN pt.sex = 'M' THEN 1 ELSE 0 END) as male_patient_count")
            ->selectRaw("SUM(CASE WHEN pt.sex = 'F' THEN 1 ELSE 0 END) as female_patient_count");

        BowScope::applyBarangayFilter($patientsRowsQuery, $request->user(), 'pt.barangay_id');

        $patientsRows = $patientsRowsQuery
            ->get()
            ->keyBy(fn ($row) => (int) $row->month_number);

        $prescriptionsRowsQuery = DB::table('bow_tbl_prescriptions as p')
            ->join('bow_tbl_patients as pt', 'pt.patient_id', '=', 'p.patient_id')
            ->where(function ($query) {
                $query->whereRaw("UPPER(COALESCE(p.release_status, '')) = 'RELEASED'")
                    ->orWhereNotNull('p.released_at');
            })
            ->whereRaw('YEAR(COALESCE(p.released_at, p.date_released, p.created_at)) = ?', [$currentYear])
            ->groupBy(DB::raw('MONTH(COALESCE(p.released_at, p.date_released, p.created_at))'))
            ->orderBy(DB::raw('MONTH(COALESCE(p.released_at, p.date_released, p.created_at))'))
            ->selectRaw('MONTH(COALESCE(p.released_at, p.date_released, p.created_at)) as month_number')
            ->selectRaw('COUNT(p.prescription_id) as released_prescription_count');

        BowScope::applyBarangayFilter($prescriptionsRowsQuery, $request->user(), 'pt.barangay_id');

        $prescriptionsRows = $prescriptionsRowsQuery
            ->get()
            ->keyBy(fn ($row) => (int) $row->month_number);

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

        $data = collect(range(1, 12))->map(function ($month) use ($monthLabels, $patientsRows, $prescriptionsRows) {
            $patientCount = isset($patientsRows[$month]) ? (int) $patientsRows[$month]->patient_count : 0;
            $malePatientCount = isset($patientsRows[$month]) ? (int) $patientsRows[$month]->male_patient_count : 0;
            $femalePatientCount = isset($patientsRows[$month]) ? (int) $patientsRows[$month]->female_patient_count : 0;
            $releasedPrescriptionCount = isset($prescriptionsRows[$month]) ? (int) $prescriptionsRows[$month]->released_prescription_count : 0;

            return [
                'month_number' => $month,
                'month_label' => $monthLabels[$month],
                'patient_count' => $patientCount,
                'male_patient_count' => $malePatientCount,
                'female_patient_count' => $femalePatientCount,
                'released_prescription_count' => $releasedPrescriptionCount,
            ];
        })->values();

        return response()->json([
            'year' => $currentYear,
            'data' => $data,
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
            ->where('p.release_status', 'RELEASED')
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

    private function buildOccupationTotals($baseQuery): array
    {
        $rows = $baseQuery
            ->selectRaw("UPPER(TRIM(r.occupation)) as raw_occupation")
            ->selectRaw('COUNT(*) as total')
            ->whereNotNull('r.occupation')
            ->whereRaw("TRIM(r.occupation) <> ''")
            ->groupBy(DB::raw("UPPER(TRIM(r.occupation))"))
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $label = $this->normalizeOccupationLabel((string) $row->raw_occupation);
            if ($label === null) {
                continue;
            }

            $totals[$label] = ($totals[$label] ?? 0) + (int) $row->total;
        }

        return $totals;
    }

    private function buildAgeEvaluationRows($baseQuery)
    {
        $rows = $baseQuery
            ->selectRaw($this->ageGroupCaseSql() . ' as age_group')
            ->selectRaw('COUNT(*) as voters')
            ->selectRaw("SUM(CASE WHEN {$this->validOccupationSql()} THEN 1 ELSE 0 END) as occupation_tagged")
            ->selectRaw("SUM(CASE WHEN r.barangay IS NOT NULL THEN 1 ELSE 0 END) as assigned_barangay")
            ->selectRaw("SUM(CASE WHEN r.purok IS NOT NULL THEN 1 ELSE 0 END) as assigned_purok")
            ->groupBy('age_group')
            ->orderByRaw($this->ageGroupSortSql())
            ->get()
            ->keyBy('age_group');

        return collect([
            'Under 18',
            '18-29',
            '30-44',
            '45-59',
            '60+',
            'Unknown',
        ])->map(function (string $ageGroup) use ($rows) {
            $row = $rows->get($ageGroup);

            return [
                'age_group' => $ageGroup,
                'voters' => $row ? (int) $row->voters : 0,
                'occupation_tagged' => $row ? (int) $row->occupation_tagged : 0,
                'assigned_barangay' => $row ? (int) $row->assigned_barangay : 0,
                'assigned_purok' => $row ? (int) $row->assigned_purok : 0,
            ];
        })->values();
    }

    private function buildTopBarangays($baseQuery, int $totalVoters)
    {
        return $baseQuery
            ->leftJoin('bow_tbl_barangays as b', 'b.barangay_id', '=', 'r.barangay')
            ->selectRaw("COALESCE(b.barangay_name, 'UN ASSIGNED') as barangay_label")
            ->selectRaw('COUNT(*) as total')
            ->groupBy('barangay_label')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->barangay_label,
                'total' => (int) $row->total,
                'share' => $totalVoters > 0 ? round(((int) $row->total / $totalVoters) * 100, 1) : 0.0,
            ])
            ->values();
    }

    private function normalizeOccupationLabel(string $value): ?string
    {
        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(['.', ',', '/', '-', '_'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if (in_array($normalized, ['\\N', '-', 'NONE', 'N A', 'NA', 'NULL', 'UNKNOWN'], true)) {
            return null;
        }

        $aliases = [
            'HOUSE WIFE' => 'HOUSEWIFE',
            'HOUSE KEEPING' => 'HOUSEKEEPING',
            'TRI CYCLE DRIVER' => 'TRICYCLE DRIVER',
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    private function validOccupationSql(): string
    {
        return "r.occupation IS NOT NULL
            AND TRIM(r.occupation) <> ''
            AND UPPER(TRIM(r.occupation)) NOT IN ('\\\\N', '-', 'NONE', 'N/A', 'NA', 'NULL', 'UNKNOWN')";
    }

    private function ageGroupCaseSql(): string
    {
        return "CASE
            WHEN r.birthdate IS NULL THEN 'Unknown'
            WHEN TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) < 18 THEN 'Under 18'
            WHEN TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) BETWEEN 18 AND 29 THEN '18-29'
            WHEN TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) BETWEEN 30 AND 44 THEN '30-44'
            WHEN TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE()) BETWEEN 45 AND 59 THEN '45-59'
            ELSE '60+'
        END";
    }

    private function ageGroupSortSql(): string
    {
        return "CASE age_group
            WHEN 'Under 18' THEN 1
            WHEN '18-29' THEN 2
            WHEN '30-44' THEN 3
            WHEN '45-59' THEN 4
            WHEN '60+' THEN 5
            ELSE 6
        END";
    }


}
