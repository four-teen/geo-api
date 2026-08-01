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
        ]);

        $barangayId = array_key_exists('barangay_id', $validated)
            ? (int) $validated['barangay_id']
            : null;
        $status = strtoupper((string) ($validated['status'] ?? 'ALL'));

        $options = BowBarangay::query()
            ->orderBy('barangay_name')
            ->get(['barangay_id', 'barangay_name', 'status']);

        $barangays = $options
            ->when(
                $barangayId !== null && $barangayId > 0,
                fn ($rows) => $rows->where('barangay_id', $barangayId)
            )
            ->when($barangayId === 0, fn ($rows) => $rows->take(0))
            ->values();

        $query = $this->reportQuery();
        $this->applyBarangayFilter($query, $barangayId);

        $allRecords = $query->get()
            ->map(fn ($record) => $this->serializeRecord($record))
            ->values()
            ->all();

        usort($allRecords, fn (array $left, array $right) => $this->compareRecords($left, $right));

        $summary = [];
        foreach ($barangays as $barangay) {
            $summary[(int) $barangay->barangay_id] = [
                'barangay_id' => (int) $barangay->barangay_id,
                'barangay_name' => (string) $barangay->barangay_name,
                'barangay_status' => strtoupper((string) $barangay->status),
                'active' => 0,
                'inactive' => 0,
                'total' => 0,
            ];
        }

        foreach ($allRecords as $record) {
            $key = (int) $record['barangay_id'];
            if (!isset($summary[$key])) {
                $summary[$key] = $this->emptySummary($record);
            }

            $summary[$key][$record['status'] === 'ACTIVE' ? 'active' : 'inactive']++;
            $summary[$key]['total']++;
        }

        $summaryRows = array_values($summary);
        usort($summaryRows, fn (array $left, array $right) => $this->compareSummaries($left, $right));
        $totals = $this->calculateTotals($summaryRows);

        $records = array_values(array_filter(
            $allRecords,
            fn (array $record) => $status === 'ALL' || $record['status'] === $status
        ));

        foreach ($records as $index => &$record) {
            $record['report_no'] = $index + 1;
        }
        unset($record);

        $hasUnassigned = $this->hasUnassignedVoters();

        return response()->json([
            'success' => true,
            'generated_at' => now()->toIso8601String(),
            'filters' => ['barangay_id' => $barangayId, 'status' => $status],
            'totals' => array_merge($totals, [
                'barangays' => count(array_filter(
                    $summaryRows,
                    fn (array $row) => $row['barangay_id'] > 0
                )),
                'filtered_records' => count($records),
            ]),
            'barangays' => $summaryRows,
            'records' => $records,
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

    private function emptySummary(array $record): array
    {
        $barangayId = (int) $record['barangay_id'];

        return [
            'barangay_id' => $barangayId,
            'barangay_name' => (string) $record['barangay_name'],
            'barangay_status' => $barangayId === 0 ? 'UNASSIGNED' : 'UNKNOWN',
            'active' => 0,
            'inactive' => 0,
            'total' => 0,
        ];
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

    private function hasUnassignedVoters(): bool
    {
        $query = $this->reportQuery();
        $this->applyBarangayFilter($query, 0);

        return $query->exists();
    }

    private function compareSummaries(array $left, array $right): int
    {
        if ($left['barangay_id'] === 0 || $right['barangay_id'] === 0) {
            if ($left['barangay_id'] === $right['barangay_id']) {
                return 0;
            }

            return $left['barangay_id'] === 0 ? 1 : -1;
        }

        return strnatcasecmp($left['barangay_name'], $right['barangay_name']);
    }

    private function compareRecords(array $left, array $right): int
    {
        foreach (['barangay_name', 'purok_name', 'precinct_no'] as $field) {
            $comparison = $this->compareLabels($left[$field], $right[$field]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        $nameComparison = strnatcasecmp($left['full_name'], $right['full_name']);
        if ($nameComparison !== 0) {
            return $nameComparison;
        }

        return $left['recipient_id'] <=> $right['recipient_id'];
    }

    private function compareLabels(string $left, string $right): int
    {
        $leftUnassigned = strcasecmp($left, 'Unassigned') === 0;
        $rightUnassigned = strcasecmp($right, 'Unassigned') === 0;

        if ($leftUnassigned || $rightUnassigned) {
            if ($leftUnassigned && $rightUnassigned) {
                return 0;
            }

            return $leftUnassigned ? 1 : -1;
        }

        return strnatcasecmp($left, $right);
    }
}
