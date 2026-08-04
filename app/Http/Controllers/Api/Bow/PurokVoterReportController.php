<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowBarangay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurokVoterReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'barangay_id' => ['required', 'integer', 'min:1', 'exists:bow_tbl_barangays,barangay_id'],
            'purok_id' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(['ALL', 'ACTIVE', 'INACTIVE'])],
        ]);

        $barangayId = (int) $validated['barangay_id'];
        $purokId = array_key_exists('purok_id', $validated) ? (int) $validated['purok_id'] : null;
        $status = strtoupper((string) ($validated['status'] ?? 'ALL'));
        $barangay = BowBarangay::query()->findOrFail($barangayId);
        $purokOptions = $this->purokOptions($barangayId);
        $groups = [];

        foreach ($this->voterRows($barangayId, $purokId, $status) as $voter) {
            $voterPurokId = (int) ($voter->purok_id ?? 0);
            $voterPurokName = trim((string) ($voter->purok_name ?? ''));
            if (!isset($groups[$voterPurokId])) {
                $groups[$voterPurokId] = [
                    'purok_id' => $voterPurokId,
                    'purok_name' => $voterPurokName !== ''
                        ? $voterPurokName
                        : ($voterPurokId > 0 ? 'Unknown Purok' : 'Unassigned'),
                    'voters' => [],
                ];
            }

            $groups[$voterPurokId]['voters'][] = $this->serializeVoter($voter);
        }

        $puroks = array_values(array_map(function (array $group) {
            $activeVoters = count(array_filter(
                $group['voters'],
                fn (array $voter) => $voter['status'] === 'ACTIVE'
            ));

            return array_merge($group, [
                'total_voters' => count($group['voters']),
                'active_voters' => $activeVoters,
                'inactive_voters' => count($group['voters']) - $activeVoters,
            ]);
        }, $groups));
        usort($puroks, [$this, 'comparePuroks']);

        return response()->json([
            'success' => true,
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'barangay_id' => $barangayId,
                'purok_id' => $purokId,
                'status' => $status,
            ],
            'barangay' => [
                'barangay_id' => $barangayId,
                'barangay_name' => (string) $barangay->barangay_name,
            ],
            'totals' => [
                'puroks' => count($puroks),
                'total_voters' => array_sum(array_column($puroks, 'total_voters')),
                'active_voters' => array_sum(array_column($puroks, 'active_voters')),
                'inactive_voters' => array_sum(array_column($puroks, 'inactive_voters')),
            ],
            'purok_options' => $purokOptions,
            'puroks' => $puroks,
        ]);
    }

    private function purokOptions(int $barangayId): array
    {
        $options = DB::table('bow_tbl_puroks')
            ->where('barangay_id', $barangayId)
            ->orderBy('purok_name')
            ->get(['purok_id', 'purok_name'])
            ->map(fn (object $purok) => [
                'purok_id' => (int) $purok->purok_id,
                'purok_name' => trim((string) $purok->purok_name) ?: 'Unknown Purok',
            ])
            ->all();

        $options[] = ['purok_id' => 0, 'purok_name' => 'Unassigned'];
        return $options;
    }

    private function voterRows(int $barangayId, ?int $purokId, string $status): array
    {
        $query = DB::table('bow_tbl_recipients as voters')
            ->leftJoin('bow_tbl_puroks as puroks', 'puroks.purok_id', '=', 'voters.purok')
            ->where('voters.barangay', $barangayId)
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
                'voters.purok as purok_id',
                'puroks.purok_name',
            ]);

        if ($purokId === 0) {
            $query->where(function ($inner) {
                $inner->whereNull('voters.purok')->orWhere('voters.purok', 0);
            });
        } elseif ($purokId !== null) {
            $query->where('voters.purok', $purokId);
        }

        $activeStatus = "UPPER(TRIM(COALESCE(voters.status, ''))) IN ('ACTIVE', 'VERIFIED', 'PENDING')";
        if ($status === 'ACTIVE') {
            $query->whereRaw($activeStatus);
        } elseif ($status === 'INACTIVE') {
            $query->whereRaw('NOT (' . $activeStatus . ')');
        }

        return $query
            ->orderByRaw("CASE WHEN puroks.purok_name IS NULL OR TRIM(puroks.purok_name) = '' THEN 1 ELSE 0 END")
            ->orderBy('puroks.purok_name')
            ->orderByRaw("CASE WHEN voters.precinct_no IS NULL OR TRIM(voters.precinct_no) = '' THEN 1 ELSE 0 END")
            ->orderBy('voters.precinct_no')
            ->orderBy('voters.last_name')
            ->orderBy('voters.first_name')
            ->orderBy('voters.recipient_id')
            ->get()
            ->all();
    }

    private function serializeVoter(object $voter): array
    {
        return [
            'recipient_id' => (int) $voter->recipient_id,
            'voters_id_number' => trim((string) ($voter->voters_id_number ?? '')),
            'full_name' => $this->formatName($voter),
            'sex' => trim((string) ($voter->sex ?? '')),
            'precinct_no' => trim((string) ($voter->precinct_no ?? '')) ?: 'Unassigned',
            'status' => $this->normalizeStatus($voter->status ?? null),
        ];
    }

    private function formatName(object $voter): string
    {
        $lastName = trim((string) ($voter->last_name ?? ''));
        $givenNames = trim(implode(' ', array_filter([
            trim((string) ($voter->first_name ?? '')),
            trim((string) ($voter->middle_name ?? '')),
            trim((string) ($voter->extension ?? '')),
        ])));

        if ($lastName !== '' && $givenNames !== '') {
            return $lastName . ', ' . $givenNames;
        }

        return $lastName !== '' ? $lastName : ($givenNames !== '' ? $givenNames : 'No name');
    }

    private function normalizeStatus(?string $status): string
    {
        return in_array(strtoupper(trim((string) $status)), ['ACTIVE', 'VERIFIED', 'PENDING'], true)
            ? 'ACTIVE'
            : 'INACTIVE';
    }

    private function comparePuroks(array $left, array $right): int
    {
        if ($left['purok_id'] === 0 || $right['purok_id'] === 0) {
            return $left['purok_id'] === $right['purok_id'] ? 0 : ($left['purok_id'] === 0 ? 1 : -1);
        }

        return strcasecmp((string) $left['purok_name'], (string) $right['purok_name']);
    }
}
