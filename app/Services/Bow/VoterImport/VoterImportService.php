<?php

namespace App\Services\Bow\VoterImport;

use App\Models\BowBarangay;
use App\Models\BowPrecinct;
use App\Models\BowPurok;
use App\Models\BowPurokAlias;
use App\Models\BowRecipient;
use App\Models\BowVoterImport;
use App\Models\BowVoterImportRow;
use App\Models\User;
use App\Support\BowScope;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class VoterImportService
{
    public function __construct(
        private readonly VoterDocxParser $parser,
        private readonly LocationNormalizer $normalizer
    ) {
    }

    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $query = BowVoterImport::query()
            ->with(['barangay:barangay_id,barangay_name', 'uploader:id,name,username']);
        BowScope::applyBarangayFilter($query, $user, 'barangay_id');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['barangay_id'])) {
            BowScope::ensureBarangayAccess($user, (int) $filters['barangay_id']);
            $query->where('barangay_id', (int) $filters['barangay_id']);
        }

        return $query->orderByDesc('import_id')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function preview(UploadedFile $file, User $user, ?int $targetBarangayId = null): BowVoterImport
    {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'docx') {
            throw ValidationException::withMessages(['file' => ['Only .docx voter masterlist files are accepted.']]);
        }

        $parsed = $this->parser->parse((string) $file->getRealPath());
        $metadata = $parsed['metadata'];
        $detectedBarangayName = trim((string) ($metadata['barangay'] ?? ''));
        $targetBarangay = null;

        if ($targetBarangayId !== null) {
            $targetBarangay = BowBarangay::query()->findOrFail($targetBarangayId);
            BowScope::ensureBarangayAccess($user, (int) $targetBarangay->barangay_id);
            if ($detectedBarangayName !== '' && !$this->normalizer->same($detectedBarangayName, $targetBarangay->barangay_name)) {
                throw ValidationException::withMessages([
                    'target_barangay_id' => ["The document header says {$detectedBarangayName}, but the selected barangay is {$targetBarangay->barangay_name}."],
                ]);
            }
        } elseif ($detectedBarangayName !== '') {
            $targetBarangay = $this->findBarangayByName($detectedBarangayName);
            if ($targetBarangay) {
                BowScope::ensureBarangayAccess($user, (int) $targetBarangay->barangay_id);
            } elseif (BowScope::hasSpecificScope($user)) {
                throw ValidationException::withMessages([
                    'file' => ['The detected barangay does not exist and your account cannot create barangays.'],
                ]);
            }
        }

        $barangayName = $targetBarangay?->barangay_name ?: $detectedBarangayName;
        if ($barangayName === '') {
            throw ValidationException::withMessages([
                'file' => ['No barangay was detected in the document header. Select a target barangay before uploading.'],
            ]);
        }

        $hash = hash_file('sha256', (string) $file->getRealPath());
        if ($hash === false) {
            throw new RuntimeException('Unable to fingerprint the uploaded document.');
        }

        $storedName = now()->format('YmdHis') . '-' . Str::random(12) . '.docx';
        $storedPath = $file->storeAs('voter-imports', $storedName, 'local');
        if (!$storedPath) {
            throw new RuntimeException('Unable to store the uploaded document for review.');
        }

        try {
            $import = DB::transaction(function () use ($parsed, $metadata, $file, $storedPath, $hash, $user, $targetBarangay, $barangayName) {
                $import = BowVoterImport::query()->create([
                    'barangay_id' => $targetBarangay?->barangay_id,
                    'barangay_name' => strtoupper($barangayName),
                    'province_name' => $metadata['province'] ?? null,
                    'municipality_name' => $metadata['municipality'] ?? null,
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_path' => $storedPath,
                    'file_hash' => $hash,
                    'status' => 'DRAFT',
                    'declared_total' => $parsed['declared_total'],
                    'parsed_rows' => count($parsed['records']),
                    'diagnostics' => $parsed['diagnostics'],
                    'uploaded_by' => $user->id,
                ]);

                $this->storeParsedRows($import, $parsed['records']);
                $this->refreshCounts($import);

                return $import;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);
            throw $exception;
        }

        return $import->fresh(['barangay', 'uploader']);
    }

    public function detail(BowVoterImport $import, User $user): array
    {
        $this->ensureAccess($import, $user);
        $import->load(['barangay:barangay_id,barangay_name', 'uploader:id,name,username']);

        $puroks = $import->barangay_id
            ? BowPurok::query()
                ->where('barangay_id', $import->barangay_id)
                ->orderBy('purok_name')
                ->get(['purok_id', 'purok_name', 'status'])
            : collect();

        $groups = $import->rows()
            ->orderBy('normalized_address')
            ->get([
                'normalized_address',
                'raw_address',
                'purok_id',
                'proposed_purok_name',
                'location_resolution',
                'match_strategy',
                'match_score',
                'remember_alias',
            ])
            ->groupBy('normalized_address')
            ->map(function (Collection $rows, string $key) use ($puroks) {
                $first = $rows->first();
                $purok = $first->purok_id
                    ? $puroks->firstWhere('purok_id', (int) $first->purok_id)
                    : null;

                return [
                    'address_key' => $key,
                    'sample_address' => $first->raw_address ?: '(blank address)',
                    'row_count' => $rows->count(),
                    'location_resolution' => $first->location_resolution,
                    'purok_id' => $first->purok_id,
                    'purok_name' => $purok?->purok_name,
                    'proposed_purok_name' => $first->proposed_purok_name,
                    'match_strategy' => $first->match_strategy,
                    'match_score' => $first->match_score !== null ? (float) $first->match_score : null,
                    'remember_alias' => (bool) $first->remember_alias,
                ];
            })
            ->sortByDesc(fn (array $group) => $group['location_resolution'] === 'PROPOSED_NEW' ? 1 : 0)
            ->values();

        return [
            'import' => $this->serializeImport($import),
            'puroks' => $puroks->values(),
            'address_groups' => $groups,
        ];
    }

    public function rows(BowVoterImport $import, User $user, array $filters): LengthAwarePaginator
    {
        $this->ensureAccess($import, $user);
        $query = $import->rows()->orderBy('source_record_no');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($inner) use ($search) {
                $inner->where('raw_name', 'like', "%{$search}%")
                    ->orWhere('raw_address', 'like', "%{$search}%")
                    ->orWhere('raw_precinct', 'like', "%{$search}%");
            });
        }

        return $query->paginate((int) ($filters['per_page'] ?? 50));
    }

    public function autoResolveDraft(BowVoterImport $import, User $user): BowVoterImport
    {
        $this->ensureEditable($import, $user);

        DB::transaction(function () use ($import) {
            $puroks = $import->barangay_id
                ? BowPurok::query()->where('barangay_id', $import->barangay_id)->get()
                : collect();
            $aliases = $import->barangay_id
                ? BowPurokAlias::query()->where('barangay_id', $import->barangay_id)->get()
                : collect();
            $precincts = BowPrecinct::query()
                ->whereIn('purok_id', $puroks->pluck('purok_id'))
                ->get()
                ->groupBy('purok_id');
            $geography = [$import->barangay_name, $import->municipality_name, $import->province_name];

            $import->rows()->orderBy('import_row_id')->chunkById(500, function ($rows) use ($puroks, $aliases, $precincts, $geography) {
                foreach ($rows as $row) {
                    $row->normalized_address = $this->normalizer->rawAddressKey($row->raw_address);
                    $row->location_key = $this->normalizer->locationKey($row->raw_address, $geography);
                    $match = $this->resolvePurok($row->location_key, $puroks, $aliases, $row->raw_address);

                    if ($match['purok_id']) {
                        $row->purok_id = $match['purok_id'];
                        $row->proposed_purok_name = null;
                        $row->location_resolution = 'MATCHED';
                        $row->match_strategy = $match['strategy'];
                        $row->match_score = $match['score'];
                        $row->precinct_id = $this->matchPrecinct(
                            $row->precinct_no,
                            $precincts->get($match['purok_id'], collect())
                        );
                    } else {
                        $row->purok_id = null;
                        $row->precinct_id = null;
                        $row->proposed_purok_name = $this->normalizer->newPurokName($row->raw_address);
                        $row->location_resolution = 'PROPOSED_NEW';
                        $row->match_strategy = 'CREATE_AS_SOURCE';
                        $row->match_score = $match['score'];
                    }

                    $this->refreshRowReview($row);
                    $row->save();
                }
            }, 'import_row_id');

            $this->refreshCounts($import);
        });

        return $import->fresh();
    }

    public function saveMappings(BowVoterImport $import, User $user, array $mappings): BowVoterImport
    {
        $this->ensureEditable($import, $user);

        DB::transaction(function () use ($import, $user, $mappings) {
            foreach ($mappings as $mapping) {
                $addressKey = (string) $mapping['address_key'];
                $rows = $import->rows()->where('normalized_address', $addressKey)->get();
                if ($rows->isEmpty()) {
                    throw ValidationException::withMessages(['mappings' => ["Address group {$addressKey} was not found in this import."]]);
                }

                $purokId = isset($mapping['purok_id']) ? (int) $mapping['purok_id'] : null;
                $newPurokName = trim((string) ($mapping['new_purok_name'] ?? ''));
                $markUnassigned = (bool) ($mapping['mark_unassigned'] ?? false);

                if ($purokId) {
                    if (!$import->barangay_id) {
                        throw ValidationException::withMessages(['mappings' => ['Existing puroks cannot be selected until the barangay exists.']]);
                    }
                    $purok = BowPurok::query()
                        ->where('barangay_id', $import->barangay_id)
                        ->find($purokId);
                    if (!$purok) {
                        throw ValidationException::withMessages(['mappings' => ['A selected purok does not belong to this import barangay.']]);
                    }
                    $resolution = 'MATCHED';
                } elseif ($newPurokName !== '') {
                    $resolution = 'PROPOSED_NEW';
                } elseif ($markUnassigned) {
                    $resolution = 'REVIEWED_UNASSIGNED';
                } else {
                    $resolution = 'UNRESOLVED';
                }

                foreach ($rows as $row) {
                    $row->purok_id = $purokId;
                    $row->precinct_id = null;
                    $row->proposed_purok_name = $newPurokName !== '' ? strtoupper($newPurokName) : null;
                    $row->location_resolution = $resolution;
                    $row->match_strategy = $resolution === 'MATCHED' ? 'MANUAL' : 'CREATE_AS_SOURCE';
                    $row->match_score = $resolution === 'MATCHED' ? 100 : null;
                    $row->remember_alias = (bool) ($mapping['remember_alias'] ?? false) && $resolution === 'MATCHED';
                    $this->refreshRowReview($row);
                    $row->save();
                }
            }

            $this->refreshCounts($import);
        });

        return $import->fresh();
    }

    public function commit(
        BowVoterImport $import,
        User $user,
        string $mode,
        string $confirmation,
        ?string $progressToken = null
    ): array
    {
        $progressToken = $progressToken ?: (string) Str::uuid();
        $totalRows = (int) $import->parsed_rows;
        $this->putProgress($progressToken, 'PREPARING', 0, $totalRows, 0, 0, 'Validating the import.');

        try {
            $this->ensureEditable($import, $user);
            if (!$this->normalizer->same($confirmation, $import->barangay_name)) {
                throw ValidationException::withMessages([
                    'confirmation' => ["Type {$import->barangay_name} exactly to confirm this import."],
                ]);
            }

            $this->refreshCounts($import);
            $import->refresh();
            if ($import->unresolved_rows > 0 || $import->error_rows > 0) {
                throw ValidationException::withMessages([
                    'import' => ['Automatic purok resolution and valid source fields are required before committing.'],
                ]);
            }

            $result = DB::transaction(function () use ($import, $user, $mode, $progressToken, $totalRows) {
                $lockedImport = BowVoterImport::query()->lockForUpdate()->findOrFail($import->import_id);
                if (!in_array($lockedImport->status, ['DRAFT', 'READY'], true)) {
                    throw ValidationException::withMessages(['import' => ['Only draft imports can be committed.']]);
                }

                $this->putProgress($progressToken, 'RESOLVING', 0, $totalRows, 0, 0, 'Creating required puroks and precincts.');
                $barangay = $this->resolveCommitBarangay($lockedImport, $user);
                $lockedImport->barangay_id = $barangay->barangay_id;
                $lockedImport->save();
                $lockedImport->rows()->update(['barangay_id' => $barangay->barangay_id]);

                $this->materializeProposedPuroks($lockedImport, $barangay, $user);
                $this->materializePrecinctsAndAliases($lockedImport, $barangay, $user);

                $this->putProgress($progressToken, 'RESETTING', 0, $totalRows, 0, 0, 'Preparing the barangay voter snapshot.');
                $replaced = 0;
                if ($mode === 'REPLACE_BARANGAY') {
                    $replaced = BowRecipient::query()->where('barangay', $barangay->barangay_id)->delete();
                }

                $existingFingerprints = $mode === 'MERGE'
                    ? BowRecipient::query()
                        ->where('barangay', $barangay->barangay_id)
                        ->whereNotNull('row_fingerprint')
                        ->pluck('row_fingerprint')
                        ->flip()
                    : collect();

                $inserted = 0;
                $skipped = 0;
                $processed = 0;
                $now = now();
                $this->putProgress($progressToken, 'INSERTING', 0, $totalRows, 0, 0, 'Inserting voter records.');

                $lockedImport->rows()->orderBy('import_row_id')->chunkById(250, function ($rows) use (
                    &$inserted,
                    &$skipped,
                    &$processed,
                    $existingFingerprints,
                    $lockedImport,
                    $barangay,
                    $now,
                    $progressToken,
                    $totalRows
                ) {
                    $batch = [];
                    foreach ($rows as $row) {
                        if ($existingFingerprints->has($row->row_fingerprint)) {
                            $skipped++;
                            continue;
                        }

                        $batch[] = [
                            'precinct_no' => $row->precinct_no,
                            'precinct_id' => $row->precinct_id,
                            'voters_id_number' => null,
                            'first_name' => $row->first_name,
                            'middle_name' => $row->middle_name,
                            'last_name' => $row->last_name,
                            'extension' => $row->extension,
                            'source_full_name' => $row->raw_name,
                            'source_address' => $row->raw_address,
                            'source_record_no' => $row->source_record_no,
                            'import_id' => $lockedImport->import_id,
                            'row_fingerprint' => $row->row_fingerprint,
                            'birthdate' => $row->birthdate?->format('Y-m-d'),
                            'occupation' => null,
                            'barangay' => $barangay->barangay_id,
                            'purok' => $row->purok_id,
                            'marital_status' => null,
                            'phone_number' => null,
                            'religion' => null,
                            'tribe_id' => null,
                            'sex' => $row->sex,
                            'profile_picture' => null,
                            'status' => 'ACTIVE',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($batch !== []) {
                        BowRecipient::query()->insert($batch);
                        $inserted += count($batch);
                    }
                    $processed += $rows->count();
                    $this->putProgress(
                        $progressToken,
                        'INSERTING',
                        $processed,
                        $totalRows,
                        $inserted,
                        $skipped,
                        'Inserting voter records.'
                    );
                }, 'import_row_id');

                $this->putProgress($progressToken, 'VERIFYING', $processed, $totalRows, $inserted, $skipped, 'Verifying inserted record counts.');
                $storedCount = BowRecipient::query()->where('import_id', $lockedImport->import_id)->count();
                $distinctSourceCount = BowRecipient::query()
                    ->where('import_id', $lockedImport->import_id)
                    ->distinct()
                    ->count('source_record_no');

                if ($processed !== $totalRows || $storedCount !== $inserted || $distinctSourceCount !== $inserted) {
                    throw new RuntimeException('Inserted voter verification failed; the transaction was rolled back.');
                }
                if ($mode === 'REPLACE_BARANGAY' && ($inserted !== $totalRows || $skipped !== 0)) {
                    throw new RuntimeException('The replacement import did not insert every parsed voter; the transaction was rolled back.');
                }

                if ($mode === 'REPLACE_BARANGAY') {
                    BowVoterImport::query()
                        ->where('barangay_id', $barangay->barangay_id)
                        ->where('import_id', '<>', $lockedImport->import_id)
                        ->where('status', 'COMMITTED')
                        ->update(['status' => 'SUPERSEDED', 'updated_at' => $now]);
                }

                $lockedImport->update([
                    'status' => 'COMMITTED',
                    'mode' => $mode,
                    'inserted_rows' => $inserted,
                    'skipped_rows' => $skipped,
                    'replaced_rows' => $replaced,
                    'committed_by' => $user->id,
                    'committed_at' => $now,
                ]);

                return [
                    'barangay_id' => (int) $barangay->barangay_id,
                    'barangay_name' => $barangay->barangay_name,
                    'inserted_rows' => $inserted,
                    'skipped_rows' => $skipped,
                    'replaced_rows' => $replaced,
                    'verified_rows' => $distinctSourceCount,
                    'mode' => $mode,
                    'progress_token' => $progressToken,
                ];
            });

            $this->putProgress(
                $progressToken,
                'COMPLETED',
                $totalRows,
                $totalRows,
                (int) $result['inserted_rows'],
                (int) $result['skipped_rows'],
                'All voter records were verified and committed.'
            );

            if ($import->stored_path) {
                Storage::disk('local')->delete($import->stored_path);
                $import->update(['stored_path' => null]);
            }

            return $result;
        } catch (Throwable $exception) {
            $this->putProgress($progressToken, 'FAILED', 0, $totalRows, 0, 0, 'Import failed and all database changes were rolled back.');
            throw $exception;
        }
    }

    public function commitProgress(string $progressToken): array
    {
        return Cache::get($this->progressCacheKey($progressToken), [
            'status' => 'WAITING',
            'processed_rows' => 0,
            'total_rows' => 0,
            'inserted_rows' => 0,
            'skipped_rows' => 0,
            'percentage' => 0,
            'message' => 'Waiting for the import to start.',
        ]);
    }

    public function deleteDraft(BowVoterImport $import, User $user): void
    {
        $this->ensureEditable($import, $user);
        if ($import->stored_path) {
            Storage::disk('local')->delete($import->stored_path);
        }
        $import->delete();
    }

    public function deleteBarangayImportData(
        BowVoterImport $import,
        User $user,
        string $confirmation
    ): array {
        $this->ensureAccess($import, $user);

        if (!$import->barangay_id || !in_array($import->status, ['COMMITTED', 'SUPERSEDED'], true)) {
            throw ValidationException::withMessages([
                'import' => ['Only a committed barangay import can remove barangay import data.'],
            ]);
        }

        if (!$this->normalizer->same($confirmation, $import->barangay_name)) {
            throw ValidationException::withMessages([
                'confirmation' => ["Type {$import->barangay_name} exactly to confirm this deletion."],
            ]);
        }

        $barangayId = (int) $import->barangay_id;
        $result = DB::transaction(function () use ($import, $barangayId) {
            $lockedImport = BowVoterImport::query()
                ->lockForUpdate()
                ->findOrFail($import->import_id);

            if ((int) $lockedImport->barangay_id !== $barangayId) {
                throw ValidationException::withMessages([
                    'import' => ['The import barangay changed. Refresh the page before deleting.'],
                ]);
            }

            $barangayImports = BowVoterImport::query()
                ->where('barangay_id', $barangayId)
                ->lockForUpdate()
                ->get(['import_id', 'stored_path']);
            $importIds = $barangayImports
                ->pluck('import_id')
                ->map(fn ($id) => (int) $id)
                ->values();
            $storedPaths = $barangayImports
                ->pluck('stored_path')
                ->filter()
                ->values()
                ->all();

            $deletedVoters = BowRecipient::query()
                ->where('barangay', $barangayId)
                ->whereNotNull('import_id')
                ->delete();

            if ($importIds->isNotEmpty()) {
                BowPurok::query()
                    ->whereIn('created_from_import_id', $importIds->all())
                    ->update([
                        'created_from_import_id' => null,
                        'updated_at' => now(),
                    ]);

                BowVoterImportRow::query()
                    ->whereIn('import_id', $importIds->all())
                    ->delete();

                BowVoterImport::query()
                    ->whereIn('import_id', $importIds->all())
                    ->delete();
            }

            return [
                'barangay_id' => $barangayId,
                'barangay_name' => $lockedImport->barangay_name,
                'deleted_voters' => (int) $deletedVoters,
                'deleted_imports' => $importIds->count(),
                'stored_paths' => $storedPaths,
            ];
        });

        if ($result['stored_paths'] !== []) {
            Storage::disk('local')->delete($result['stored_paths']);
        }
        unset($result['stored_paths']);

        return $result;
    }

    public function serializeImport(BowVoterImport $import): array
    {
        return [
            'import_id' => (int) $import->import_id,
            'barangay_id' => $import->barangay_id ? (int) $import->barangay_id : null,
            'barangay_name' => $import->barangay_name,
            'province_name' => $import->province_name,
            'municipality_name' => $import->municipality_name,
            'original_filename' => $import->original_filename,
            'file_hash' => $import->file_hash,
            'status' => $import->status,
            'mode' => $import->mode,
            'declared_total' => $import->declared_total,
            'parsed_rows' => $import->parsed_rows,
            'ready_rows' => $import->ready_rows,
            'warning_rows' => $import->warning_rows,
            'error_rows' => $import->error_rows,
            'unresolved_rows' => $import->unresolved_rows,
            'inserted_rows' => $import->inserted_rows,
            'skipped_rows' => $import->skipped_rows,
            'replaced_rows' => $import->replaced_rows,
            'diagnostics' => $import->diagnostics,
            'uploaded_by_name' => $import->uploader?->name ?: $import->uploader?->username,
            'committed_at' => $import->committed_at?->toISOString(),
            'created_at' => $import->created_at?->toISOString(),
            'updated_at' => $import->updated_at?->toISOString(),
            'can_commit' => in_array($import->status, ['DRAFT', 'READY'], true)
                && (int) $import->error_rows === 0
                && (int) $import->unresolved_rows === 0,
        ];
    }

    private function storeParsedRows(BowVoterImport $import, array $records): void
    {
        $puroks = $import->barangay_id
            ? BowPurok::query()->where('barangay_id', $import->barangay_id)->get()
            : collect();
        $aliases = $import->barangay_id
            ? BowPurokAlias::query()->where('barangay_id', $import->barangay_id)->get()
            : collect();
        $precincts = BowPrecinct::query()
            ->whereIn('purok_id', $puroks->pluck('purok_id'))
            ->get()
            ->groupBy('purok_id');

        $rows = [];
        $now = now();
        $geography = [$import->barangay_name, $import->municipality_name, $import->province_name];

        foreach ($records as $record) {
            $names = $this->parseName($record['raw_name']);
            [$birthdate, $dateIssue] = $this->parseBirthdate($record['raw_birthdate']);
            [$sex, $sexIssue] = $this->parseSex($record['raw_sex']);
            [$precinctNo, $precinctIssue] = $this->parsePrecinct($record['raw_precinct']);
            $addressKey = $this->normalizer->rawAddressKey($record['raw_address']);
            $locationKey = $this->normalizer->locationKey($record['raw_address'], $geography);
            $match = $this->resolvePurok($locationKey, $puroks, $aliases, $record['raw_address']);
            $purokId = $match['purok_id'];
            $resolution = $purokId ? 'MATCHED' : 'PROPOSED_NEW';
            $proposedPurokName = $purokId ? null : $this->normalizer->newPurokName($record['raw_address']);
            $precinctId = $purokId
                ? $this->matchPrecinct($precinctNo, $precincts->get($purokId, collect()))
                : null;

            $parseIssues = array_values(array_filter([
                $record['raw_name'] === '' ? $this->issue('error', 'MISSING_NAME', 'Voter name is missing.') : null,
                $dateIssue,
                $sexIssue,
                $precinctIssue,
            ]));
            $reviewIssues = $this->reviewIssues($parseIssues, $resolution, $precinctNo, $precinctId);
            $status = $this->rowStatus($parseIssues, $reviewIssues, $resolution);
            $fingerprint = hash('sha256', implode('|', [
                $this->normalizer->text($record['raw_name']),
                $birthdate ?: '',
                $sex ?: '',
                $precinctNo ?: '',
                $this->normalizer->text($record['raw_address']),
            ]));

            $rows[] = [
                'import_id' => $import->import_id,
                'source_record_no' => $record['source_record_no'],
                'raw_name' => $record['raw_name'],
                'raw_address' => $record['raw_address'] ?: null,
                'raw_birthdate' => $record['raw_birthdate'] ?: null,
                'raw_sex' => $record['raw_sex'] ?: null,
                'raw_precinct' => $record['raw_precinct'] ?: null,
                'normalized_address' => $addressKey,
                'location_key' => $locationKey ?: null,
                'first_name' => $names['first_name'],
                'middle_name' => $names['middle_name'],
                'last_name' => $names['last_name'],
                'extension' => $names['extension'],
                'birthdate' => $birthdate,
                'sex' => $sex,
                'precinct_no' => $precinctNo,
                'barangay_id' => $import->barangay_id,
                'purok_id' => $purokId,
                'precinct_id' => $precinctId,
                'proposed_purok_name' => $proposedPurokName,
                'location_resolution' => $resolution,
                'match_strategy' => $purokId ? $match['strategy'] : 'CREATE_AS_SOURCE',
                'match_score' => $match['score'],
                'status' => $status,
                'row_fingerprint' => $fingerprint,
                'parse_issues' => json_encode($parseIssues),
                'issues' => json_encode($reviewIssues),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= 500) {
                BowVoterImportRow::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            BowVoterImportRow::query()->insert($rows);
        }
    }

    private function parseName(string $rawName): array
    {
        $rawName = trim(preg_replace('/\s+/', ' ', $rawName) ?? '');
        $segments = array_values(array_filter(array_map('trim', explode(',', $rawName)), fn ($value) => $value !== ''));
        $lastName = $segments[0] ?? null;
        $extension = null;
        $givenPart = implode(' ', array_slice($segments, 1));

        foreach (['JR', 'SR', 'II', 'III', 'IV', 'V'] as $suffix) {
            $pattern = '/(?:^|\s)' . preg_quote($suffix, '/') . '\.?$/i';
            if ($lastName && preg_match($pattern, $lastName)) {
                $extension = $suffix;
                $lastName = trim(preg_replace($pattern, '', $lastName) ?? $lastName);
                break;
            }
            if ($givenPart && preg_match($pattern, $givenPart)) {
                $extension = $suffix;
                $givenPart = trim(preg_replace($pattern, '', $givenPart) ?? $givenPart);
                break;
            }
        }

        if (count($segments) > 2 && $extension === null && preg_match('/^(JR\.?|SR\.?|II|III|IV|V)$/i', $segments[1])) {
            $extension = strtoupper(rtrim($segments[1], '.'));
            $givenPart = implode(' ', array_slice($segments, 2));
        }

        $givenTokens = preg_split('/\s+/', trim($givenPart), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $middleName = count($givenTokens) >= 2 ? array_pop($givenTokens) : null;
        $firstName = implode(' ', $givenTokens);
        if ($firstName === '' && $middleName !== null) {
            $firstName = $middleName;
            $middleName = null;
        }

        return [
            'first_name' => $firstName !== '' ? strtoupper($firstName) : null,
            'middle_name' => $middleName ? strtoupper($middleName) : null,
            'last_name' => $lastName ? strtoupper($lastName) : null,
            'extension' => $extension,
        ];
    }

    private function parseBirthdate(string $raw): array
    {
        $raw = trim($raw);
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', $raw);
        if (!$date || $date->format('d/m/Y') !== $raw) {
            return [null, $this->issue('error', 'INVALID_BIRTHDATE', 'Birthday must use DD/MM/YYYY and be a valid date.')];
        }

        return [$date->format('Y-m-d'), null];
    }

    private function parseSex(string $raw): array
    {
        $value = strtoupper(trim($raw));
        if (in_array($value, ['M', 'MALE'], true)) {
            return ['MALE', null];
        }
        if (in_array($value, ['F', 'FEMALE'], true)) {
            return ['FEMALE', null];
        }

        return [null, $this->issue('error', 'INVALID_SEX', 'Sex must be M or F.')];
    }

    private function parsePrecinct(string $raw): array
    {
        $code = $this->normalizer->precinctCode($raw);
        if (!preg_match('/^\d{4}[A-Z]$/', $code)) {
            return [$code ?: null, $this->issue('error', 'INVALID_PRECINCT', 'Precinct must look like 0077A.')];
        }

        return [$code, null];
    }

    private function resolvePurok(
        string $locationKey,
        Collection $puroks,
        Collection $aliases,
        mixed $sourceAddress = null
    ): array
    {
        $sourceMatches = $puroks->filter(fn ($purok) => $this->normalizer->exactSource(
            $purok->purok_name,
            $sourceAddress
        ));
        if ($sourceMatches->count() === 1) {
            return [
                'purok_id' => (int) $sourceMatches->first()->purok_id,
                'strategy' => 'EXACT_SOURCE',
                'score' => 100.0,
            ];
        }

        if ($locationKey === '') {
            return ['purok_id' => null, 'strategy' => 'CREATE_AS_SOURCE', 'score' => 0.0];
        }

        $alias = $aliases->first(fn ($item) => $this->normalizer->same($item->alias_normalized, $locationKey));
        if ($alias) {
            return ['purok_id' => (int) $alias->purok_id, 'strategy' => 'ALIAS', 'score' => 100.0];
        }

        $exactMatches = $puroks->filter(fn ($purok) => $this->normalizer->same(
            $this->normalizer->purokKey($purok->purok_name),
            $locationKey
        ));
        if ($exactMatches->count() === 1) {
            return ['purok_id' => (int) $exactMatches->first()->purok_id, 'strategy' => 'EXACT', 'score' => 100.0];
        }

        $ranked = $puroks->map(function ($purok) use ($locationKey) {
            $candidateKey = $this->normalizer->purokKey($purok->purok_name);
            return [
                'purok_id' => (int) $purok->purok_id,
                'score' => $this->normalizer->similarity($locationKey, $candidateKey),
                'distance' => $this->normalizer->editDistance($locationKey, $candidateKey),
            ];
        })->sortByDesc('score')->values();

        $best = $ranked->get(0);
        $second = $ranked->get(1);
        $compactLength = strlen($this->normalizer->compact($locationKey));
        $margin = ($best['score'] ?? 0) - ($second['score'] ?? 0);
        $isClearlyClose = $best && $compactLength >= 5 && (
            $best['score'] >= 85.0
            || $best['distance'] <= 2
        );
        if ($isClearlyClose && ($second === null || $margin >= 6.0)) {
            return ['purok_id' => $best['purok_id'], 'strategy' => 'CLOSE_MATCH', 'score' => $best['score']];
        }

        return [
            'purok_id' => null,
            'strategy' => 'CREATE_AS_SOURCE',
            'score' => (float) ($best['score'] ?? 0.0),
        ];
    }

    private function matchPrecinct(?string $code, Collection $precincts): ?int
    {
        if (!$code) {
            return null;
        }
        $match = $precincts->first(fn ($precinct) => $this->normalizer->precinctCode($precinct->precinct_name) === $code);
        return $match ? (int) $match->precinct_id : null;
    }

    private function refreshRowReview(BowVoterImportRow $row): void
    {
        if ($row->purok_id) {
            $precincts = BowPrecinct::query()->where('purok_id', $row->purok_id)->get();
            $row->precinct_id = $this->matchPrecinct($row->precinct_no, $precincts);
        }

        $parseIssues = $row->parse_issues ?: [];
        $issues = $this->reviewIssues($parseIssues, $row->location_resolution, $row->precinct_no, $row->precinct_id);
        $row->issues = $issues;
        $row->status = $this->rowStatus($parseIssues, $issues, $row->location_resolution);
    }

    private function reviewIssues(array $parseIssues, string $resolution, ?string $precinctNo, ?int $precinctId): array
    {
        $issues = $parseIssues;
        if ($resolution === 'UNRESOLVED') {
            $issues[] = $this->issue('warning', 'UNRESOLVED_ADDRESS', 'Choose an existing purok, propose a new purok, or mark this address as reviewed/unassigned.');
        } elseif ($resolution === 'REVIEWED_UNASSIGNED') {
            $issues[] = $this->issue('warning', 'UNASSIGNED_PUROK', 'This voter will be saved under the barangay without a purok.');
        } elseif ($resolution === 'PROPOSED_NEW') {
            $issues[] = $this->issue('warning', 'PUROK_WILL_BE_CREATED', 'The source address will be created automatically as a new purok during commit.');
        }

        if ($precinctNo && !$precinctId && $resolution !== 'UNRESOLVED' && $resolution !== 'REVIEWED_UNASSIGNED') {
            $issues[] = $this->issue('warning', 'PRECINCT_WILL_BE_CREATED', 'This precinct/purok pair will be created during commit.');
        }

        return $issues;
    }

    private function rowStatus(array $parseIssues, array $issues, string $resolution): string
    {
        if (collect($parseIssues)->contains(fn ($issue) => ($issue['severity'] ?? '') === 'error')) {
            return 'ERROR';
        }
        if ($resolution === 'UNRESOLVED') {
            return 'REVIEW_REQUIRED';
        }
        if ($issues !== []) {
            return 'WARNING';
        }

        return 'READY';
    }

    private function issue(string $severity, string $code, string $message): array
    {
        return compact('severity', 'code', 'message');
    }

    private function refreshCounts(BowVoterImport $import): void
    {
        $counts = $import->rows()
            ->selectRaw("SUM(CASE WHEN status = 'READY' THEN 1 ELSE 0 END) AS ready_rows")
            ->selectRaw("SUM(CASE WHEN status = 'WARNING' THEN 1 ELSE 0 END) AS warning_rows")
            ->selectRaw("SUM(CASE WHEN status = 'ERROR' THEN 1 ELSE 0 END) AS error_rows")
            ->selectRaw("SUM(CASE WHEN location_resolution = 'UNRESOLVED' THEN 1 ELSE 0 END) AS unresolved_rows")
            ->first();

        $errorRows = (int) ($counts->error_rows ?? 0);
        $unresolvedRows = (int) ($counts->unresolved_rows ?? 0);
        $import->update([
            'ready_rows' => (int) ($counts->ready_rows ?? 0),
            'warning_rows' => (int) ($counts->warning_rows ?? 0),
            'error_rows' => $errorRows,
            'unresolved_rows' => $unresolvedRows,
            'status' => $errorRows === 0 && $unresolvedRows === 0 ? 'READY' : 'DRAFT',
        ]);
    }

    private function findBarangayByName(string $name): ?BowBarangay
    {
        return BowBarangay::query()->get()->first(fn ($barangay) => $this->normalizer->same($barangay->barangay_name, $name));
    }

    private function resolveCommitBarangay(BowVoterImport $import, User $user): BowBarangay
    {
        if ($import->barangay_id) {
            BowScope::ensureBarangayAccess($user, (int) $import->barangay_id);
            return BowBarangay::query()->findOrFail($import->barangay_id);
        }
        if (BowScope::hasSpecificScope($user)) {
            throw ValidationException::withMessages(['barangay' => ['Your account cannot create a new barangay.']]);
        }

        $existing = $this->findBarangayByName($import->barangay_name);
        return $existing ?: BowBarangay::query()->create([
            'barangay_name' => strtoupper($import->barangay_name),
            'status' => 'ACTIVE',
        ]);
    }

    private function materializeProposedPuroks(BowVoterImport $import, BowBarangay $barangay, User $user): void
    {
        $proposedNames = $import->rows()
            ->where('location_resolution', 'PROPOSED_NEW')
            ->whereNotNull('proposed_purok_name')
            ->distinct()
            ->pluck('proposed_purok_name');

        foreach ($proposedNames as $name) {
            $puroks = BowPurok::query()
                ->where('barangay_id', $barangay->barangay_id)
                ->get();
            $existing = $puroks->first(fn ($purok) => $this->normalizer->exactSource($purok->purok_name, $name))
                ?: $puroks->first(fn ($purok) => $this->normalizer->same($purok->purok_name, $name));
            $purok = $existing ?: BowPurok::query()->create([
                'barangay_id' => $barangay->barangay_id,
                'purok_name' => strtoupper($name),
                'status' => 'ACTIVE',
                'created_from_import_id' => $import->import_id,
            ]);

            $import->rows()
                ->where('location_resolution', 'PROPOSED_NEW')
                ->where('proposed_purok_name', $name)
                ->update([
                    'purok_id' => $purok->purok_id,
                    'location_resolution' => 'MATCHED',
                    'match_strategy' => $existing ? 'EXACT' : 'CREATED',
                    'match_score' => $existing ? 100 : 0,
                    'updated_at' => now(),
                ]);
        }
    }

    private function materializePrecinctsAndAliases(BowVoterImport $import, BowBarangay $barangay, User $user): void
    {
        $rows = $import->rows()->orderBy('source_record_no')->get();
        $precinctCache = [];

        foreach ($rows as $row) {
            if ($row->purok_id && $row->precinct_no) {
                $cacheKey = $row->purok_id . '|' . $row->precinct_no;
                if (!isset($precinctCache[$cacheKey])) {
                    $existing = BowPrecinct::query()
                        ->where('purok_id', $row->purok_id)
                        ->get()
                        ->first(fn ($precinct) => $this->normalizer->precinctCode($precinct->precinct_name) === $row->precinct_no);
                    $precinct = $existing ?: BowPrecinct::query()->create([
                        'purok_id' => $row->purok_id,
                        'precinct_name' => $row->precinct_no,
                        'status' => 'ACTIVE',
                    ]);
                    $precinctCache[$cacheKey] = (int) $precinct->precinct_id;
                }
                $row->precinct_id = $precinctCache[$cacheKey];
            }

            if (($row->remember_alias || $row->match_strategy === 'CLOSE_MATCH') && $row->purok_id && $row->location_key) {
                BowPurokAlias::query()->updateOrCreate(
                    [
                        'barangay_id' => $barangay->barangay_id,
                        'alias_normalized' => $row->location_key,
                    ],
                    [
                        'purok_id' => $row->purok_id,
                        'alias_text' => $row->raw_address ?: $row->normalized_address,
                        'approved_by' => $user->id,
                    ]
                );
            }
            $row->save();
        }
    }

    private function putProgress(
        string $progressToken,
        string $status,
        int $processedRows,
        int $totalRows,
        int $insertedRows,
        int $skippedRows,
        string $message
    ): void {
        $percentage = $totalRows > 0
            ? min(100, (int) floor(($processedRows / $totalRows) * 100))
            : 0;
        if ($status === 'COMPLETED') {
            $percentage = 100;
        }

        Cache::put($this->progressCacheKey($progressToken), [
            'status' => $status,
            'processed_rows' => $processedRows,
            'total_rows' => $totalRows,
            'inserted_rows' => $insertedRows,
            'skipped_rows' => $skippedRows,
            'percentage' => $percentage,
            'message' => $message,
            'updated_at' => now()->toISOString(),
        ], now()->addHour());
    }

    private function progressCacheKey(string $progressToken): string
    {
        return 'bow:voter-import-progress:' . $progressToken;
    }

    private function ensureAccess(BowVoterImport $import, User $user): void
    {
        if ($import->barangay_id) {
            BowScope::ensureBarangayAccess($user, (int) $import->barangay_id);
        } elseif (BowScope::hasSpecificScope($user)) {
            throw ValidationException::withMessages(['import' => ['You cannot access an unassigned barangay import.']]);
        }
    }

    private function ensureEditable(BowVoterImport $import, User $user): void
    {
        $this->ensureAccess($import, $user);
        if (!in_array($import->status, ['DRAFT', 'READY'], true)) {
            throw ValidationException::withMessages(['import' => ['This import is already committed and cannot be changed.']]);
        }
    }
}
