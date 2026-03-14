<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const RECIPIENTS_TABLE = 'bow_tbl_recipients';
    private const BARANGAYS_TABLE = 'bow_tbl_barangays';
    private const PUROKS_TABLE = 'bow_tbl_puroks';
    private const FK_RECIPIENTS_BARANGAY = 'fk_recipients_barangay';
    private const FK_RECIPIENTS_PUROK = 'fk_recipients_purok';
    private const IDX_RECIPIENTS_PUROK_FK = 'idx_recipients_purok_fk';

    public function up(): void
    {
        if (
            !Schema::hasTable(self::RECIPIENTS_TABLE)
            || !Schema::hasTable(self::BARANGAYS_TABLE)
            || !Schema::hasTable(self::PUROKS_TABLE)
        ) {
            return;
        }

        $this->normalizeRecipientLocations();

        if (!$this->isMySql()) {
            return;
        }

        $this->convertRecipientColumnsToNullableSignedInts();
        $this->ensureRecipientForeignKeyIndexes();
        $this->addRecipientForeignKeys();
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::RECIPIENTS_TABLE)) {
            return;
        }

        $this->dropRecipientForeignKeys();
        $this->dropRecipientForeignKeyIndexes();

        if (!$this->isMySql()) {
            return;
        }

        DB::statement(
            'ALTER TABLE `' . self::RECIPIENTS_TABLE . '` '
            . 'MODIFY `barangay` VARCHAR(200) NULL, '
            . 'MODIFY `purok` VARCHAR(200) NULL'
        );
    }

    private function normalizeRecipientLocations(): void
    {
        $barangayIndex = $this->buildBarangayIndex();
        $purokIndex = $this->buildPurokIndex();

        DB::table(self::RECIPIENTS_TABLE)
            ->select(['recipient_id', 'barangay', 'purok'])
            ->orderBy('recipient_id')
            ->chunkById(500, function ($rows) use ($barangayIndex, $purokIndex) {
                $updates = [];

                foreach ($rows as $row) {
                    $barangayId = $this->resolveBarangayId($row->barangay, $barangayIndex);
                    $purokId = $this->resolvePurokId($row->purok, $barangayId, $purokIndex);

                    if (
                        $this->toPositiveIntOrNull($row->barangay) === $barangayId
                        && $this->toPositiveIntOrNull($row->purok) === $purokId
                    ) {
                        continue;
                    }

                    $updates[] = [
                        'recipient_id' => (int) $row->recipient_id,
                        'barangay' => $barangayId,
                        'purok' => $purokId,
                    ];
                }

                if (!empty($updates)) {
                    DB::table(self::RECIPIENTS_TABLE)->upsert(
                        $updates,
                        ['recipient_id'],
                        ['barangay', 'purok']
                    );
                }
            }, 'recipient_id');
    }

    private function buildBarangayIndex(): array
    {
        $index = [
            'valid_ids' => [],
            'by_normalized' => [],
            'by_compact' => [],
        ];

        $rows = DB::table(self::BARANGAYS_TABLE)
            ->get(['barangay_id', 'barangay_name']);

        foreach ($rows as $row) {
            $barangayId = (int) $row->barangay_id;
            $normalized = $this->normalizeLocationName($row->barangay_name);
            $compact = $normalized === null ? null : $this->compactLocationName($normalized);

            $index['valid_ids'][$barangayId] = true;

            if ($normalized !== null) {
                $index['by_normalized'][$normalized] = $barangayId;
            }

            if ($compact !== null && $compact !== '') {
                $index['by_compact'][$compact] = $barangayId;
            }
        }

        return $index;
    }

    private function buildPurokIndex(): array
    {
        $index = [];

        $rows = DB::table(self::PUROKS_TABLE)
            ->orderBy('barangay_id')
            ->orderBy('purok_id')
            ->get(['purok_id', 'barangay_id', 'purok_name']);

        foreach ($rows as $row) {
            $barangayId = (int) $row->barangay_id;
            $normalized = $this->normalizeLocationName($row->purok_name);
            $compact = $normalized === null ? null : $this->compactLocationName($normalized);

            if (!isset($index[$barangayId])) {
                $index[$barangayId] = [
                    'records' => [],
                    'valid_ids' => [],
                    'by_normalized' => [],
                    'by_compact' => [],
                ];
            }

            $record = [
                'id' => (int) $row->purok_id,
                'normalized' => $normalized,
                'compact' => $compact,
            ];

            $index[$barangayId]['records'][] = $record;
            $index[$barangayId]['valid_ids'][$record['id']] = true;

            if ($normalized !== null) {
                $index[$barangayId]['by_normalized'][$normalized][] = $record['id'];
            }

            if ($compact !== null && $compact !== '') {
                $index[$barangayId]['by_compact'][$compact][] = $record['id'];
            }
        }

        return $index;
    }

    private function resolveBarangayId(mixed $rawValue, array $barangayIndex): ?int
    {
        $numericId = $this->toPositiveIntOrNull($rawValue);
        if ($numericId !== null) {
            return isset($barangayIndex['valid_ids'][$numericId]) ? $numericId : null;
        }

        $normalized = $this->normalizeLocationName($rawValue);
        if ($normalized === null) {
            return null;
        }

        if (isset($barangayIndex['by_normalized'][$normalized])) {
            return (int) $barangayIndex['by_normalized'][$normalized];
        }

        $compact = $this->compactLocationName($normalized);
        if ($compact !== '' && isset($barangayIndex['by_compact'][$compact])) {
            return (int) $barangayIndex['by_compact'][$compact];
        }

        return null;
    }

    private function resolvePurokId(mixed $rawValue, ?int $barangayId, array $purokIndex): ?int
    {
        if ($barangayId === null || !isset($purokIndex[$barangayId])) {
            return null;
        }

        $bucket = $purokIndex[$barangayId];

        $numericId = $this->toPositiveIntOrNull($rawValue);
        if ($numericId !== null) {
            return isset($bucket['valid_ids'][$numericId]) ? $numericId : null;
        }

        $normalized = $this->normalizeLocationName($rawValue);
        if ($normalized === null) {
            return null;
        }

        $compact = $this->compactLocationName($normalized);

        $exactIds = $bucket['by_normalized'][$normalized] ?? [];
        if (count($exactIds) === 1) {
            return (int) $exactIds[0];
        }

        $compactIds = $bucket['by_compact'][$compact] ?? [];
        if (count($compactIds) === 1) {
            return (int) $compactIds[0];
        }

        $bestId = null;
        $bestScore = 0;
        $isTied = false;

        foreach ($bucket['records'] as $record) {
            $recordCompact = $record['compact'] ?? '';
            if ($recordCompact === '') {
                continue;
            }

            $score = $this->matchCompactNames($compact, $recordCompact);
            if ($score === 0) {
                continue;
            }

            if ($score > $bestScore) {
                $bestId = (int) $record['id'];
                $bestScore = $score;
                $isTied = false;
                continue;
            }

            if ($score === $bestScore && $bestId !== (int) $record['id']) {
                $isTied = true;
            }
        }

        if ($bestId === null || $isTied) {
            return null;
        }

        return $bestId;
    }

    private function matchCompactNames(string $value, string $candidate): int
    {
        if ($value === '' || $candidate === '') {
            return 0;
        }

        if ($value === $candidate) {
            return 1000 + strlen($candidate);
        }

        if (str_starts_with($value, $candidate) || str_starts_with($candidate, $value)) {
            return 500 + min(strlen($value), strlen($candidate));
        }

        if (str_contains($value, $candidate) || str_contains($candidate, $value)) {
            return 200 + min(strlen($value), strlen($candidate));
        }

        return 0;
    }

    private function normalizeLocationName(mixed $value): ?string
    {
        $normalized = Str::upper(Str::ascii(trim((string) $value)));
        if (
            $normalized === ''
            || in_array($normalized, ['0', '-', 'N/A', 'NA', 'NONE', 'NULL', '\\N', 'UNASSIGNED', 'UN ASSIGNED'], true)
        ) {
            return null;
        }

        $normalized = preg_replace('/[^A-Z0-9]+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/^(PUROK|PRK)\s+/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bSUBDIVISION\b|\bSUBD\b/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return $normalized === '' ? null : $normalized;
    }

    private function compactLocationName(string $value): string
    {
        return str_replace(' ', '', $value);
    }

    private function toPositiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || !preg_match('/^\d+$/', $normalized)) {
            return null;
        }

        $integer = (int) $normalized;
        return $integer > 0 ? $integer : null;
    }

    private function convertRecipientColumnsToNullableSignedInts(): void
    {
        DB::statement(
            'ALTER TABLE `' . self::RECIPIENTS_TABLE . '` '
            . 'MODIFY `barangay` INT NULL DEFAULT NULL, '
            . 'MODIFY `purok` INT NULL DEFAULT NULL'
        );
    }

    private function ensureRecipientForeignKeyIndexes(): void
    {
        if ($this->indexExists(self::IDX_RECIPIENTS_PUROK_FK)) {
            return;
        }

        Schema::table(self::RECIPIENTS_TABLE, function (Blueprint $table) {
            $table->index('purok', self::IDX_RECIPIENTS_PUROK_FK);
        });
    }

    private function addRecipientForeignKeys(): void
    {
        $needsBarangayForeignKey = !$this->foreignKeyExists(self::FK_RECIPIENTS_BARANGAY);
        $needsPurokForeignKey = !$this->foreignKeyExists(self::FK_RECIPIENTS_PUROK);

        if (!$needsBarangayForeignKey && !$needsPurokForeignKey) {
            return;
        }

        Schema::table(self::RECIPIENTS_TABLE, function (Blueprint $table) use ($needsBarangayForeignKey, $needsPurokForeignKey) {
            if ($needsBarangayForeignKey) {
                $table->foreign('barangay', self::FK_RECIPIENTS_BARANGAY)
                    ->references('barangay_id')
                    ->on(self::BARANGAYS_TABLE)
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }

            if ($needsPurokForeignKey) {
                $table->foreign('purok', self::FK_RECIPIENTS_PUROK)
                    ->references('purok_id')
                    ->on(self::PUROKS_TABLE)
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }
        });
    }

    private function dropRecipientForeignKeys(): void
    {
        $dropBarangayForeignKey = $this->foreignKeyExists(self::FK_RECIPIENTS_BARANGAY);
        $dropPurokForeignKey = $this->foreignKeyExists(self::FK_RECIPIENTS_PUROK);

        if (!$dropBarangayForeignKey && !$dropPurokForeignKey) {
            return;
        }

        Schema::table(self::RECIPIENTS_TABLE, function (Blueprint $table) use ($dropBarangayForeignKey, $dropPurokForeignKey) {
            if ($dropBarangayForeignKey) {
                $table->dropForeign(self::FK_RECIPIENTS_BARANGAY);
            }

            if ($dropPurokForeignKey) {
                $table->dropForeign(self::FK_RECIPIENTS_PUROK);
            }
        });
    }

    private function dropRecipientForeignKeyIndexes(): void
    {
        if (!$this->indexExists(self::IDX_RECIPIENTS_PUROK_FK)) {
            return;
        }

        Schema::table(self::RECIPIENTS_TABLE, function (Blueprint $table) {
            $table->dropIndex(self::IDX_RECIPIENTS_PUROK_FK);
        });
    }

    private function foreignKeyExists(string $constraintName): bool
    {
        if (!$this->isMySql()) {
            return false;
        }

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::RECIPIENTS_TABLE)
            ->where('CONSTRAINT_NAME', $constraintName)
            ->exists();
    }

    private function indexExists(string $indexName): bool
    {
        if (!$this->isMySql()) {
            return false;
        }

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::RECIPIENTS_TABLE)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    private function isMySql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }
};
