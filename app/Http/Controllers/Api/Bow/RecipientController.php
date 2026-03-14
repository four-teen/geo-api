<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowBarangay;
use App\Models\BowPurok;
use App\Models\BowRecipient;
use App\Support\BowScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RecipientController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:50'],
            'barangay' => ['nullable', 'string', 'max:200'],
            'purok' => ['nullable', 'string', 'max:200'],
            'barangay_id' => ['nullable', 'integer', 'min:0'],
            'purok_id' => ['nullable', 'integer', 'min:0'],
            'precinct_no' => ['nullable', 'string', 'max:100'],
            'sort_by' => ['nullable', Rule::in(['updated_at', 'barangay', 'purok', 'precinct_no'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $barangayFilter = array_key_exists('barangay_id', $validated)
            ? $validated['barangay_id']
            : ($validated['barangay'] ?? null);

        $purokFilter = array_key_exists('purok_id', $validated)
            ? $validated['purok_id']
            : ($validated['purok'] ?? null);

        $scopedQuery = $this->recipientListQuery();
        $this->applyScopedBarangayFilter($scopedQuery, $request);
        $totalCount = (clone $scopedQuery)->count();

        $query = clone $scopedQuery;

        if (!empty($validated['status'])) {
            $query->where('bow_tbl_recipients.status', (string) $validated['status']);
        }

        $this->applyBarangaySelectionFilter($query, $barangayFilter);
        $this->applyPurokSelectionFilter($query, $purokFilter);

        if (!empty($validated['precinct_no'])) {
            $query->where('bow_tbl_recipients.precinct_no', (string) $validated['precinct_no']);
        }

        if (!empty($validated['search'])) {
            $keyword = trim((string) $validated['search']);
            $query->where(function (Builder $inner) use ($keyword) {
                $inner->where('bow_tbl_recipients.first_name', 'like', "%{$keyword}%")
                    ->orWhere('bow_tbl_recipients.middle_name', 'like', "%{$keyword}%")
                    ->orWhere('bow_tbl_recipients.last_name', 'like', "%{$keyword}%")
                    ->orWhere('bow_tbl_recipients.voters_id_number', 'like', "%{$keyword}%")
                    ->orWhere('bow_tbl_recipients.precinct_no', 'like', "%{$keyword}%")
                    ->orWhere('bow_tbl_recipients.barangay', 'like', "%{$keyword}%")
                    ->orWhere('bow_tbl_recipients.purok', 'like', "%{$keyword}%")
                    ->orWhere('recipient_barangay_lookup.barangay_name', 'like', "%{$keyword}%")
                    ->orWhere('recipient_purok_lookup.purok_name', 'like', "%{$keyword}%");

                if (Str::contains(Str::lower($keyword), ['un assigned', 'unassigned'])) {
                    $inner->orWhere(function (Builder $unassigned) {
                        $this->applyUnassignedLocationFilter($unassigned, 'bow_tbl_recipients.barangay', 'recipient_barangay_lookup.barangay_id');
                    });
                }
            });
        }

        $filteredCount = (clone $query)->count();

        $sortBy = (string) ($validated['sort_by'] ?? 'updated_at');
        $sortDir = (string) ($validated['sort_dir'] ?? 'desc');

        $this->applySort($query, $sortBy, $sortDir);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $data = collect($paginator->items())
            ->map(fn (BowRecipient $recipient) => $this->serializeRecipient($recipient))
            ->values();

        $hasUnassignedBarangay = (clone $scopedQuery)
            ->where(function (Builder $inner) {
                $this->applyUnassignedLocationFilter($inner, 'bow_tbl_recipients.barangay', 'recipient_barangay_lookup.barangay_id');
            })
            ->exists();

        $barangayOptions = (clone $scopedQuery)
            ->whereNotNull('recipient_barangay_lookup.barangay_id')
            ->select('recipient_barangay_lookup.barangay_name as linked_barangay_name')
            ->distinct()
            ->orderBy('recipient_barangay_lookup.barangay_name')
            ->pluck('linked_barangay_name')
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

        if ($hasUnassignedBarangay) {
            array_unshift($barangayOptions, 'Un Assigned');
        }

        $hasUnassignedPurok = (clone $scopedQuery)
            ->where(function (Builder $inner) {
                $this->applyUnassignedLocationFilter($inner, 'bow_tbl_recipients.purok', 'recipient_purok_lookup.purok_id');
            })
            ->exists();

        $purokOptions = (clone $scopedQuery)
            ->whereNotNull('recipient_purok_lookup.purok_id')
            ->select('recipient_purok_lookup.purok_name as linked_purok_name')
            ->distinct()
            ->orderBy('recipient_purok_lookup.purok_name')
            ->pluck('linked_purok_name')
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

        if ($hasUnassignedPurok) {
            array_unshift($purokOptions, 'Un Assigned');
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'counts' => [
                'filtered' => $filteredCount,
                'total' => $totalCount,
                'display' => "{$filteredCount}/{$totalCount}",
            ],
            'filters' => [
                'barangay' => array_merge(['None'], $barangayOptions),
                'purok' => array_merge(['None'], $purokOptions),
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'precinct_no' => ['nullable', 'string', 'max:100'],
            'voters_id_number' => ['nullable', 'string', 'max:120'],
            'first_name' => ['required', 'string', 'max:150'],
            'middle_name' => ['nullable', 'string', 'max:150'],
            'last_name' => ['required', 'string', 'max:150'],
            'extension' => ['nullable', 'string', 'max:50'],
            'birthdate' => ['nullable', 'date'],
            'occupation' => ['nullable', 'string', 'max:200'],
            'barangay_id' => ['required', 'integer', 'min:0'],
            'purok_id' => ['nullable', 'integer', 'min:0'],
            'marital_status' => ['nullable', 'string', 'max:50'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'religion' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', 'string', 'max:20'],
            'profile_picture' => ['nullable', 'image', 'max:3072'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $location = $this->resolveRecipientLocationSelection($request, $validated);

        $payload = [
            'precinct_no' => $validated['precinct_no'] ?? null,
            'voters_id_number' => $validated['voters_id_number'] ?? null,
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'extension' => $validated['extension'] ?? null,
            'birthdate' => $validated['birthdate'] ?? null,
            'occupation' => $validated['occupation'] ?? null,
            'barangay' => $location['barangay_id'],
            'purok' => $location['purok_id'],
            'marital_status' => $validated['marital_status'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'religion' => $validated['religion'] ?? null,
            'sex' => $validated['sex'] ?? null,
            'status' => $this->normalizeVoterStatus($validated['status'] ?? 'ACTIVE'),
            'profile_picture' => null,
        ];

        if ($request->hasFile('profile_picture')) {
            $payload['profile_picture'] = $this->storeProfilePicture($request->file('profile_picture'));
        }

        $recipient = null;
        DB::transaction(function () use ($payload, &$recipient) {
            $recipient = new BowRecipient();
            $recipient->fill($payload);
            $recipient->recipient_id = $this->nextLegacyId('bow_tbl_recipients', 'recipient_id');
            $recipient->save();
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Voter created successfully.',
            'data' => $this->serializeRecipient($recipient->loadMissing(['barangayRecord', 'purokRecord'])),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $recipient = BowRecipient::query()->findOrFail($id);
        $this->ensureRecipientAccess($request, $recipient);

        $validated = $request->validate([
            'precinct_no' => ['nullable', 'string', 'max:100'],
            'voters_id_number' => ['nullable', 'string', 'max:120'],
            'first_name' => ['required', 'string', 'max:150'],
            'middle_name' => ['nullable', 'string', 'max:150'],
            'last_name' => ['required', 'string', 'max:150'],
            'extension' => ['nullable', 'string', 'max:50'],
            'birthdate' => ['nullable', 'date'],
            'occupation' => ['nullable', 'string', 'max:200'],
            'barangay_id' => ['required', 'integer', 'min:0'],
            'purok_id' => ['nullable', 'integer', 'min:0'],
            'marital_status' => ['nullable', 'string', 'max:50'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'religion' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', 'string', 'max:20'],
            'profile_picture' => ['nullable', 'image', 'max:3072'],
            'remove_profile_picture' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $location = $this->resolveRecipientLocationSelection($request, $validated);

        $payload = [
            'precinct_no' => $validated['precinct_no'] ?? null,
            'voters_id_number' => $validated['voters_id_number'] ?? null,
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'extension' => $validated['extension'] ?? null,
            'birthdate' => $validated['birthdate'] ?? null,
            'occupation' => $validated['occupation'] ?? null,
            'barangay' => $location['barangay_id'],
            'purok' => $location['purok_id'],
            'marital_status' => $validated['marital_status'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'religion' => $validated['religion'] ?? null,
            'sex' => $validated['sex'] ?? null,
            'status' => $this->normalizeVoterStatus($validated['status'] ?? (string) $recipient->status),
        ];

        $removeOldPicture = false;
        if ($request->hasFile('profile_picture')) {
            $payload['profile_picture'] = $this->storeProfilePicture($request->file('profile_picture'));
            $removeOldPicture = true;
        } elseif ((bool) ($validated['remove_profile_picture'] ?? false)) {
            $payload['profile_picture'] = null;
            $removeOldPicture = true;
        }

        $oldPicturePath = (string) ($recipient->profile_picture ?? '');

        $recipient->update($payload);

        if ($removeOldPicture && $oldPicturePath !== '') {
            $this->removeProfilePictureFile($oldPicturePath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Voter updated successfully.',
            'data' => $this->serializeRecipient($recipient->fresh(['barangayRecord', 'purokRecord'])),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $recipient = BowRecipient::query()->findOrFail($id);
        $this->ensureRecipientAccess($request, $recipient);

        $oldPicturePath = (string) ($recipient->profile_picture ?? '');
        $recipient->delete();

        if ($oldPicturePath !== '') {
            $this->removeProfilePictureFile($oldPicturePath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Voter deleted successfully.',
        ]);
    }

    private function applyScopedBarangayFilter(Builder $query, Request $request): void
    {
        $allowedBarangayIds = BowScope::allowedBarangayIds($request->user());
        if ($allowedBarangayIds === null) {
            return;
        }

        if (count($allowedBarangayIds) === 0) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn('bow_tbl_recipients.barangay', $allowedBarangayIds);
    }

    private function ensureRecipientAccess(Request $request, BowRecipient $recipient): void
    {
        $allowedBarangayIds = BowScope::allowedBarangayIds($request->user());
        if ($allowedBarangayIds === null) {
            return;
        }

        $recipientBarangayId = $this->normalizeLocationId($recipient->barangay);
        if ($recipientBarangayId === null || !in_array($recipientBarangayId, $allowedBarangayIds, true)) {
            throw new HttpException(403, 'You are not allowed to access this voter.');
        }
    }

    private function serializeRecipient(BowRecipient $recipient): array
    {
        $fullName = trim(implode(' ', array_filter([
            $recipient->first_name,
            $recipient->middle_name,
            $recipient->last_name,
            $recipient->extension,
        ])));

        $attributes = $recipient->attributesToArray();
        unset(
            $attributes['linked_barangay_id'],
            $attributes['linked_barangay_name'],
            $attributes['linked_purok_id'],
            $attributes['linked_purok_name']
        );

        $barangayId = $this->normalizeLocationId(
            $recipient->getAttribute('linked_barangay_id')
                ?? $recipient->barangayRecord?->barangay_id
                ?? $recipient->barangay
        );

        $barangayName = trim((string) (
            $recipient->getAttribute('linked_barangay_name')
                ?? $recipient->barangayRecord?->barangay_name
                ?? ''
        ));

        if ($barangayId === null && $barangayName === '') {
            $legacyBarangay = trim((string) $recipient->getRawOriginal('barangay'));
            if ($legacyBarangay !== '' && $this->toIntegerOrNull($legacyBarangay) === null) {
                $barangayName = $legacyBarangay;
            }
        }

        $purokId = $this->normalizeLocationId(
            $recipient->getAttribute('linked_purok_id')
                ?? $recipient->purokRecord?->purok_id
                ?? $recipient->purok
        );

        $purokName = trim((string) (
            $recipient->getAttribute('linked_purok_name')
                ?? $recipient->purokRecord?->purok_name
                ?? ''
        ));

        if ($purokId === null && $purokName === '') {
            $legacyPurok = trim((string) $recipient->getRawOriginal('purok'));
            if ($legacyPurok !== '' && $this->toIntegerOrNull($legacyPurok) === null) {
                $purokName = $legacyPurok;
            }
        }

        $barangayLabel = $barangayId === null
            ? 'Un Assigned'
            : ($barangayName !== '' ? $barangayName : 'Unknown Barangay');

        $purokLabel = $purokId === null
            ? 'Un Assigned'
            : ($purokName !== '' ? $purokName : 'Unknown Purok');

        return array_merge($attributes, [
            'barangay' => $barangayLabel,
            'barangay_id' => $barangayId ?? 0,
            'barangay_name' => $barangayName !== '' ? $barangayName : null,
            'purok' => $purokLabel,
            'purok_id' => $purokId ?? 0,
            'purok_name' => $purokName !== '' ? $purokName : null,
            'is_unassigned' => $barangayId === null,
            'full_name' => $fullName,
            'profile_picture_url' => $this->resolveProfilePictureUrl($recipient->profile_picture),
        ]);
    }

    private function resolveProfilePictureUrl(?string $path): ?string
    {
        $normalized = trim((string) $path);
        if ($normalized === '') {
            return null;
        }

        if (Str::startsWith($normalized, ['http://', 'https://', 'data:'])) {
            return $normalized;
        }

        return url('/' . ltrim($normalized, '/'));
    }

    private function storeProfilePicture(UploadedFile $file): string
    {
        $directory = public_path('uploads/recipients');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create recipients upload directory.');
        }

        $extension = $file->guessExtension() ?: ($file->getClientOriginalExtension() ?: 'jpg');
        $fileName = Str::uuid()->toString() . '.' . strtolower($extension);
        $file->move($directory, $fileName);

        return 'uploads/recipients/' . $fileName;
    }

    private function removeProfilePictureFile(?string $path): void
    {
        $normalized = trim((string) $path);
        if ($normalized === '') {
            return;
        }

        if (Str::startsWith($normalized, ['http://', 'https://', 'data:'])) {
            return;
        }

        $absolutePath = public_path(ltrim($normalized, '/'));
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function nextLegacyId(string $table, string $idColumn): int
    {
        $maxId = DB::table($table)
            ->selectRaw("COALESCE(MAX({$idColumn}), 0) as max_id")
            ->lockForUpdate()
            ->value('max_id');

        return ((int) $maxId) + 1;
    }

    private function recipientListQuery(): Builder
    {
        return BowRecipient::query()
            ->leftJoin('bow_tbl_barangays as recipient_barangay_lookup', 'recipient_barangay_lookup.barangay_id', '=', 'bow_tbl_recipients.barangay')
            ->leftJoin('bow_tbl_puroks as recipient_purok_lookup', 'recipient_purok_lookup.purok_id', '=', 'bow_tbl_recipients.purok')
            ->select([
                'bow_tbl_recipients.*',
                'recipient_barangay_lookup.barangay_id as linked_barangay_id',
                'recipient_barangay_lookup.barangay_name as linked_barangay_name',
                'recipient_purok_lookup.purok_id as linked_purok_id',
                'recipient_purok_lookup.purok_name as linked_purok_name',
            ]);
    }

    private function applyBarangaySelectionFilter(Builder $query, mixed $value): void
    {
        if ($this->isIgnoredFilterValue($value)) {
            return;
        }

        $selectedId = $this->toIntegerOrNull($value);
        if ($selectedId !== null) {
            if ($selectedId <= 0) {
                $this->applyUnassignedLocationFilter($query, 'bow_tbl_recipients.barangay', 'recipient_barangay_lookup.barangay_id');
                return;
            }

            $query->where('bow_tbl_recipients.barangay', $selectedId);
            return;
        }

        $normalized = trim((string) $value);
        if ($this->isUnassignedFilterLabel($normalized)) {
            $this->applyUnassignedLocationFilter($query, 'bow_tbl_recipients.barangay', 'recipient_barangay_lookup.barangay_id');
            return;
        }

        $query->where(function (Builder $inner) use ($normalized) {
            $inner->where('recipient_barangay_lookup.barangay_name', $normalized)
                ->orWhere('bow_tbl_recipients.barangay', $normalized);
        });
    }

    private function applyPurokSelectionFilter(Builder $query, mixed $value): void
    {
        if ($this->isIgnoredFilterValue($value)) {
            return;
        }

        $selectedId = $this->toIntegerOrNull($value);
        if ($selectedId !== null) {
            if ($selectedId <= 0) {
                $this->applyUnassignedLocationFilter($query, 'bow_tbl_recipients.purok', 'recipient_purok_lookup.purok_id');
                return;
            }

            $query->where('bow_tbl_recipients.purok', $selectedId);
            return;
        }

        $normalized = trim((string) $value);
        if ($this->isUnassignedFilterLabel($normalized)) {
            $this->applyUnassignedLocationFilter($query, 'bow_tbl_recipients.purok', 'recipient_purok_lookup.purok_id');
            return;
        }

        $query->where(function (Builder $inner) use ($normalized) {
            $inner->where('recipient_purok_lookup.purok_name', $normalized)
                ->orWhere('bow_tbl_recipients.purok', $normalized);
        });
    }

    private function applyUnassignedLocationFilter(Builder $query, string $column, string $joinedIdColumn): void
    {
        $query->where(function (Builder $inner) use ($column, $joinedIdColumn) {
            $inner->whereNull($column)
                ->orWhere($column, 0)
                ->orWhere($column, '0')
                ->orWhereNull($joinedIdColumn);
        });
    }

    private function applySort(Builder $query, string $sortBy, string $sortDir): void
    {
        if ($sortBy === 'updated_at') {
            $query->orderBy('bow_tbl_recipients.updated_at', $sortDir)
                ->orderBy('bow_tbl_recipients.recipient_id', $sortDir);
            return;
        }

        if ($sortBy === 'barangay') {
            $query->orderByRaw(
                'CASE WHEN bow_tbl_recipients.barangay IS NULL OR bow_tbl_recipients.barangay = 0 THEN 0 ELSE 1 END '
                . ($sortDir === 'asc' ? 'ASC' : 'DESC')
            )
                ->orderBy('recipient_barangay_lookup.barangay_name', $sortDir)
                ->orderBy('bow_tbl_recipients.barangay', $sortDir)
                ->orderByDesc('bow_tbl_recipients.recipient_id');
            return;
        }

        if ($sortBy === 'purok') {
            $query->orderByRaw(
                'CASE WHEN bow_tbl_recipients.purok IS NULL OR bow_tbl_recipients.purok = 0 THEN 0 ELSE 1 END '
                . ($sortDir === 'asc' ? 'ASC' : 'DESC')
            )
                ->orderBy('recipient_purok_lookup.purok_name', $sortDir)
                ->orderBy('bow_tbl_recipients.purok', $sortDir)
                ->orderByDesc('bow_tbl_recipients.recipient_id');
            return;
        }

        $query->orderBy('bow_tbl_recipients.precinct_no', $sortDir)
            ->orderByDesc('bow_tbl_recipients.recipient_id');
    }

    private function resolveRecipientLocationSelection(Request $request, array $validated): array
    {
        $barangayId = $this->normalizeLocationId($validated['barangay_id'] ?? null);
        $purokId = $this->normalizeLocationId($validated['purok_id'] ?? null);

        if ($barangayId === null) {
            if ($purokId !== null) {
                throw ValidationException::withMessages([
                    'purok_id' => ['You cannot assign a purok while the barangay is unassigned.'],
                ]);
            }

            $this->ensureUnassignedRecipientMutationAllowed($request);

            return [
                'barangay_id' => null,
                'purok_id' => null,
            ];
        }

        $barangay = BowBarangay::query()->findOrFail($barangayId);
        BowScope::ensureBarangayAccess($request->user(), $barangay->barangay_id);

        if ($purokId === null) {
            return [
                'barangay_id' => (int) $barangay->barangay_id,
                'purok_id' => null,
            ];
        }

        $purok = BowPurok::query()->findOrFail($purokId);
        if ((int) $purok->barangay_id !== (int) $barangay->barangay_id) {
            throw ValidationException::withMessages([
                'purok_id' => ['Selected purok does not belong to selected barangay.'],
            ]);
        }

        return [
            'barangay_id' => (int) $barangay->barangay_id,
            'purok_id' => (int) $purok->purok_id,
        ];
    }

    private function ensureUnassignedRecipientMutationAllowed(Request $request): void
    {
        if (BowScope::allowedBarangayIds($request->user()) !== null) {
            throw new HttpException(403, 'You are not allowed to assign voters to the unassigned location.');
        }
    }

    private function normalizeLocationId(mixed $value): ?int
    {
        $integer = $this->toIntegerOrNull($value);
        if ($integer === null || $integer <= 0) {
            return null;
        }

        return $integer;
    }

    private function toIntegerOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || !preg_match('/^-?\d+$/', $normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    private function isIgnoredFilterValue(mixed $value): bool
    {
        $normalized = trim((string) $value);
        return $normalized === '' || Str::lower($normalized) === 'none';
    }

    private function isUnassignedFilterLabel(string $value): bool
    {
        $normalized = Str::lower(trim($value));
        return $normalized === 'un assigned' || $normalized === 'unassigned';
    }

    private function normalizeVoterStatus(?string $status): string
    {
        $normalized = Str::upper(trim((string) $status));
        if ($normalized === 'ENACTIVE' || $normalized === 'INACTIVE' || $normalized === 'DISQUALIFIED') {
            return 'INACTIVE';
        }

        if ($normalized === 'VERIFIED' || $normalized === 'PENDING' || $normalized === '') {
            return 'ACTIVE';
        }

        return $normalized;
    }
}
