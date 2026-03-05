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
            'precinct_no' => ['nullable', 'string', 'max:100'],
            'sort_by' => ['nullable', Rule::in(['updated_at', 'barangay', 'purok', 'precinct_no'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $normalizedBarangay = $this->normalizeFilterValue($validated['barangay'] ?? null);
        $normalizedPurok = $this->normalizeFilterValue($validated['purok'] ?? null);

        $scopedQuery = BowRecipient::query();
        $this->applyScopedBarangayFilter($scopedQuery, $request);
        $totalCount = (clone $scopedQuery)->count();

        $query = clone $scopedQuery;

        if (!empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if ($normalizedBarangay !== null) {
            $query->where('barangay', $normalizedBarangay);
        }

        if ($normalizedPurok !== null) {
            $query->where('purok', $normalizedPurok);
        }

        if (!empty($validated['precinct_no'])) {
            $query->where('precinct_no', (string) $validated['precinct_no']);
        }

        if (!empty($validated['search'])) {
            $keyword = trim((string) $validated['search']);
            $query->where(function (Builder $inner) use ($keyword) {
                $inner->where('first_name', 'like', "%{$keyword}%")
                    ->orWhere('middle_name', 'like', "%{$keyword}%")
                    ->orWhere('last_name', 'like', "%{$keyword}%")
                    ->orWhere('voters_id_number', 'like', "%{$keyword}%")
                    ->orWhere('precinct_no', 'like', "%{$keyword}%")
                    ->orWhere('barangay', 'like', "%{$keyword}%")
                    ->orWhere('purok', 'like', "%{$keyword}%");
            });
        }

        $filteredCount = (clone $query)->count();

        $sortBy = (string) ($validated['sort_by'] ?? 'updated_at');
        $sortDir = (string) ($validated['sort_dir'] ?? 'desc');

        if ($sortBy === 'updated_at') {
            if ($sortDir === 'asc') {
                $query->orderBy('updated_at', 'asc')->orderBy('recipient_id', 'asc');
            } else {
                $query->orderByDesc('updated_at')->orderByDesc('recipient_id');
            }
        } else {
            $query->orderBy($sortBy, $sortDir)->orderByDesc('recipient_id');
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $data = collect($paginator->items())
            ->map(fn (BowRecipient $recipient) => $this->serializeRecipient($recipient))
            ->values();

        $barangayOptions = (clone $scopedQuery)
            ->select('barangay')
            ->whereNotNull('barangay')
            ->where('barangay', '<>', '')
            ->distinct()
            ->orderBy('barangay')
            ->pluck('barangay')
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

        $purokOptions = (clone $scopedQuery)
            ->select('purok')
            ->whereNotNull('purok')
            ->where('purok', '<>', '')
            ->distinct()
            ->orderBy('purok')
            ->pluck('purok')
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

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
            'barangay_id' => ['required', 'integer', 'exists:bow_tbl_barangays,barangay_id'],
            'purok_id' => ['required', 'integer', 'exists:bow_tbl_puroks,purok_id'],
            'marital_status' => ['nullable', 'string', 'max:50'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'religion' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', 'string', 'max:20'],
            'profile_picture' => ['nullable', 'image', 'max:3072'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $barangay = BowBarangay::query()->findOrFail((int) $validated['barangay_id']);
        BowScope::ensureBarangayAccess($request->user(), (int) $barangay->barangay_id);

        $purok = BowPurok::query()->findOrFail((int) $validated['purok_id']);
        if ((int) $purok->barangay_id !== (int) $barangay->barangay_id) {
            throw ValidationException::withMessages([
                'purok_id' => ['Selected purok does not belong to selected barangay.'],
            ]);
        }

        $payload = [
            'precinct_no' => $validated['precinct_no'] ?? null,
            'voters_id_number' => $validated['voters_id_number'] ?? null,
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'extension' => $validated['extension'] ?? null,
            'birthdate' => $validated['birthdate'] ?? null,
            'occupation' => $validated['occupation'] ?? null,
            'barangay' => $barangay->barangay_name,
            'purok' => $purok->purok_name,
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
            'data' => $this->serializeRecipient($recipient),
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
            'barangay_id' => ['required', 'integer', 'exists:bow_tbl_barangays,barangay_id'],
            'purok_id' => ['required', 'integer', 'exists:bow_tbl_puroks,purok_id'],
            'marital_status' => ['nullable', 'string', 'max:50'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'religion' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', 'string', 'max:20'],
            'profile_picture' => ['nullable', 'image', 'max:3072'],
            'remove_profile_picture' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $barangay = BowBarangay::query()->findOrFail((int) $validated['barangay_id']);
        BowScope::ensureBarangayAccess($request->user(), (int) $barangay->barangay_id);

        $purok = BowPurok::query()->findOrFail((int) $validated['purok_id']);
        if ((int) $purok->barangay_id !== (int) $barangay->barangay_id) {
            throw ValidationException::withMessages([
                'purok_id' => ['Selected purok does not belong to selected barangay.'],
            ]);
        }

        $payload = [
            'precinct_no' => $validated['precinct_no'] ?? null,
            'voters_id_number' => $validated['voters_id_number'] ?? null,
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'extension' => $validated['extension'] ?? null,
            'birthdate' => $validated['birthdate'] ?? null,
            'occupation' => $validated['occupation'] ?? null,
            'barangay' => $barangay->barangay_name,
            'purok' => $purok->purok_name,
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
            'data' => $this->serializeRecipient($recipient->fresh()),
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

        $allowedBarangayNames = BowBarangay::query()
            ->whereIn('barangay_id', $allowedBarangayIds)
            ->pluck('barangay_name')
            ->all();

        if (count($allowedBarangayNames) === 0) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn('barangay', $allowedBarangayNames);
    }

    private function ensureRecipientAccess(Request $request, BowRecipient $recipient): void
    {
        $allowedBarangayIds = BowScope::allowedBarangayIds($request->user());
        if ($allowedBarangayIds === null) {
            return;
        }

        $allowedBarangayNames = BowBarangay::query()
            ->whereIn('barangay_id', $allowedBarangayIds)
            ->pluck('barangay_name')
            ->map(fn ($name) => Str::lower(trim((string) $name)))
            ->all();

        $recipientBarangay = Str::lower(trim((string) $recipient->barangay));
        if (!in_array($recipientBarangay, $allowedBarangayNames, true)) {
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

        return array_merge($recipient->toArray(), [
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

    private function normalizeFilterValue($value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || Str::lower($normalized) === 'none') {
            return null;
        }

        return $normalized;
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
