<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowBarangay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VoterReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'barangay_id' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(['ALL', 'ACTIVE', 'INACTIVE'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $barangayId = array_key_exists('barangay_id', $validated)
            ? (int) $validated['barangay_id']
            : null;
        $status = strtoupper((string) ($validated['status'] ?? 'ALL'));
        $search = trim((string) ($validated['search'] ?? ''));

        $options = BowBarangay::query()
            ->orderBy('barangay_name')
            ->get(['barangay_id', 'barangay_name', 'status']);
        $counts = $this->summaryCounts($barangayId);
        $filteredCounts = $search !== ''
            ? $this->filteredSummaryCounts($barangayId, $status, $search)
            : collect();
        $summaryRows = [];

        foreach ($options as $barangay) {
            $id = (int) $barangay->barangay_id;
            if (($barangayId !== null && $barangayId !== $id) || $barangayId === 0) {
                continue;
            }

            $summaryRows[] = $this->summaryRow(
                $id,
                (string) $barangay->barangay_name,
                strtoupper((string) $barangay->status),
                $counts->get($id),
                $filteredCounts->get($id),
                $status,
                $search
            );
        }

        if (($barangayId === null || $barangayId === 0) && $counts->has(0)) {
            $summaryRows[] = $this->summaryRow(
                0,
                'Unassigned',
                'UNASSIGNED',
                $counts->get(0),
                $filteredCounts->get(0),
                $status,
                $search
            );
        }

        $totals = $this->calculateTotals($summaryRows);
        $filteredTotal = array_sum(array_column($summaryRows, 'filtered_records'));
        $hasUnassigned = $counts->has(0);

        return response()->json([
            'success' => true,
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'barangay_id' => $barangayId,
                'status' => $status,
                'search' => $search,
            ],
            'totals' => array_merge($totals, [
                'barangays' => count(array_filter(
                    $summaryRows,
                    fn (array $row) => $row['barangay_id'] > 0
                )),
                'filtered_records' => $filteredTotal,
            ]),
            'barangays' => $summaryRows,
            'records' => [],
            'records_included' => false,
            'barangay_options' => $options
                ->map(fn (BowBarangay $barangay) => [
                    'barangay_id' => (int) $barangay->barangay_id,
                    'barangay_name' => (string) $barangay->barangay_name,
                ])
                ->when($hasUnassigned, fn ($rows) => $rows->push([
                    'barangay_id' => 0,
                    'barangay_name' => 'Unassigned',
                ]))
                ->values(),
        ]);
    }

    private function summaryQuery()
    {
        return DB::table('bow_tbl_recipients as voters')
            ->leftJoin('bow_tbl_barangays as barangays', 'barangays.barangay_id', '=', 'voters.barangay')
            ->leftJoin('bow_tbl_puroks as puroks', 'puroks.purok_id', '=', 'voters.purok');
    }

    private function summaryCounts(?int $barangayId)
    {
        $active = 'UPPER(TRIM(COALESCE(voters.status, \'\'))) IN (\'ACTIVE\', \'VERIFIED\', \'PENDING\')';
        $query = $this->summaryQuery()
            ->selectRaw('COALESCE(barangays.barangay_id, 0) as barangay_id')
            ->selectRaw('SUM(CASE WHEN ' . $active . ' THEN 1 ELSE 0 END) as active')
            ->selectRaw('SUM(CASE WHEN ' . $active . ' THEN 0 ELSE 1 END) as inactive')
            ->selectRaw('COUNT(*) as total')
            ->groupByRaw('COALESCE(barangays.barangay_id, 0)');

        $this->applyBarangayFilter($query, $barangayId);
        return $query->get()->keyBy(fn ($row) => (int) $row->barangay_id);
    }

    private function filteredSummaryCounts(?int $barangayId, string $status, string $search)
    {
        $query = $this->summaryQuery()
            ->selectRaw('COALESCE(barangays.barangay_id, 0) as barangay_id')
            ->selectRaw('COUNT(*) as filtered_records')
            ->groupByRaw('COALESCE(barangays.barangay_id, 0)');

        $this->applyBarangayFilter($query, $barangayId);
        $this->applyStatusFilter($query, $status);
        $this->applySearchFilter($query, $search);
        return $query->get()->keyBy(fn ($row) => (int) $row->barangay_id);
    }

    private function summaryRow(
        int $id,
        string $name,
        string $barangayStatus,
        ?object $count,
        ?object $filteredCount,
        string $status,
        string $search
    ): array {
        $active = (int) ($count->active ?? 0);
        $inactive = (int) ($count->inactive ?? 0);
        $total = (int) ($count->total ?? 0);
        $filtered = $search !== ''
            ? (int) ($filteredCount->filtered_records ?? 0)
            : match ($status) {
                'ACTIVE' => $active,
                'INACTIVE' => $inactive,
                default => $total,
            };

        return [
            'barangay_id' => $id,
            'barangay_name' => $name,
            'barangay_status' => $barangayStatus,
            'active' => $active,
            'inactive' => $inactive,
            'total' => $total,
            'filtered_records' => $filtered,
        ];
    }

    private function reportQuery()
    {
        return DB::table('bow_tbl_recipients as voters')
            ->leftJoin('bow_tbl_barangays as barangays', 'barangays.barangay_id', '=', 'voters.barangay')
            ->leftJoin('bow_tbl_puroks as puroks', 'puroks.purok_id', '=', 'voters.purok')
            ->select([
                'voters.recipient_id',
                'voters.voters_id_number',
                'voters.first_name',
                'voters.middle_name',
                'voters.last_name',
                'voters.extension',
                'voters.sex',
                'voters.precinct_no',
                'voters.status',
                'barangays.barangay_id as linked_barangay_id',
                'barangays.barangay_name',
                'puroks.purok_id as linked_purok_id',
                'puroks.purok_name',
            ]);
    }

    private function applyBarangayFilter($query, ?int $barangayId): void
    {
        if ($barangayId !== null && $barangayId > 0) {
            $query->where('voters.barangay', $barangayId);
            return;
        }

        if ($barangayId === 0) {
            $query->where(function ($inner) {
                $inner->whereNull('voters.barangay')
                    ->orWhere('voters.barangay', 0)
                    ->orWhereNull('barangays.barangay_id');
            });
        }
    }

    private function serializeRecord(object $record): array
    {
        $barangayId = (int) ($record->linked_barangay_id ?? 0);
        $purokId = (int) ($record->linked_purok_id ?? 0);

        return [
            'recipient_id' => (int) $record->recipient_id,
            'report_no' => 0,
            'voters_id_number' => trim((string) ($record->voters_id_number ?? '')),
            'full_name' => $this->formatName($record),
            'last_name' => trim((string) ($record->last_name ?? '')),
            'first_name' => trim((string) ($record->first_name ?? '')),
            'sex' => trim((string) ($record->sex ?? '')),
            'barangay_id' => $barangayId,
            'barangay_name' => $barangayId > 0
                ? trim((string) ($record->barangay_name ?? 'Unknown Barangay'))
                : 'Unassigned',
            'purok_id' => $purokId,
            'purok_name' => $purokId > 0
                ? trim((string) ($record->purok_name ?? 'Unknown Purok'))
                : 'Unassigned',
            'precinct_no' => trim((string) ($record->precinct_no ?? '')) ?: 'Unassigned',
            'status' => $this->normalizeStatus($record->status ?? null),
        ];
    }

    private function formatName(object $record): string
    {
        $lastName = trim((string) ($record->last_name ?? ''));
        $givenNames = trim(implode(' ', array_filter([
            trim((string) ($record->first_name ?? '')),
            trim((string) ($record->middle_name ?? '')),
            trim((string) ($record->extension ?? '')),
        ])));

        if ($lastName !== '' && $givenNames !== '') {
            return $lastName . ', ' . $givenNames;
        }

        return $lastName !== '' ? $lastName : ($givenNames !== '' ? $givenNames : 'No name');
    }

    private function normalizeStatus(?string $status): string
    {
        $normalized = strtoupper(trim((string) $status));

        return in_array($normalized, ['ACTIVE', 'VERIFIED', 'PENDING'], true)
            ? 'ACTIVE'
            : 'INACTIVE';
    }

    private function calculateTotals(array $summaryRows): array
    {
        return array_reduce($summaryRows, function (array $carry, array $row) {
            $carry['active'] += $row['active'];
            $carry['inactive'] += $row['inactive'];
            $carry['total'] += $row['total'];
            return $carry;
        }, ['active' => 0, 'inactive' => 0, 'total' => 0]);
    }

    private function applyStatusFilter($query, string $status): void
    {
        if ($status === 'ALL') {
            return;
        }

        $active = ['ACTIVE', 'VERIFIED', 'PENDING'];
        if ($status === 'ACTIVE') {
            $query->whereIn('voters.status', $active);
            return;
        }

        $query->where(function ($inner) use ($active) {
            $inner->whereNull('voters.status')
                ->orWhereNotIn('voters.status', $active);
        });
    }

    private function applySearchFilter($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%' . mb_strtolower($search) . '%';
        $query->where(function ($inner) use ($like) {
            $inner->whereRaw(
                'LOWER(CONCAT_WS(\' \', voters.first_name, voters.middle_name, voters.last_name, voters.extension)) LIKE ?',
                [$like]
            )
                ->orWhereRaw('LOWER(COALESCE(voters.voters_id_number, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(puroks.purok_name, \'Unassigned\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(voters.precinct_no, \'Unassigned\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(voters.status, \'\')) LIKE ?', [$like]);
        });
    }

    private function applyReportOrdering($query): void
    {
        $query
            ->orderByRaw('CASE WHEN puroks.purok_name IS NULL OR TRIM(puroks.purok_name) = \'\' THEN 1 ELSE 0 END')
            ->orderBy('puroks.purok_name')
            ->orderByRaw('CASE WHEN voters.precinct_no IS NULL OR TRIM(voters.precinct_no) = \'\' THEN 1 ELSE 0 END')
            ->orderBy('voters.precinct_no')
            ->orderBy('voters.last_name')
            ->orderBy('voters.first_name')
            ->orderBy('voters.recipient_id');
    }

    public function records(Request $request)
    {
        $validated = $request->validate([
            'barangay_id' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(['ALL', 'ACTIVE', 'INACTIVE'])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);

        $barangayId = (int) $validated['barangay_id'];
        $status = strtoupper((string) ($validated['status'] ?? 'ALL'));
        $search = trim((string) ($validated['search'] ?? ''));
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);
        $query = $this->reportQuery();
        $this->applyBarangayFilter($query, $barangayId);
        $this->applyStatusFilter($query, $status);
        $this->applySearchFilter($query, $search);
        $this->applyReportOrdering($query);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $offset = ($paginator->currentPage() - 1) * $paginator->perPage();
        $records = $paginator->getCollection()->values()
            ->map(function ($record, int $index) use ($offset) {
                $row = $this->serializeRecord($record);
                $row['report_no'] = $offset + $index + 1;
                return $row;
            })->all();

        return response()->json([
            'success' => true,
            'generated_at' => now()->toIso8601String(),
            'records' => $records,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
}
