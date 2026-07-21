<?php

namespace App\Console\Commands;

use App\Services\Bow\VoterImport\VoterDocxParser;
use Illuminate\Console\Command;

class InspectVoterDocx extends Command
{
    protected $signature = 'bow:voters:inspect-docx {file : Absolute path to a voter .docx file}';
    protected $description = 'Parse a voter DOCX without saving anything to the database';

    public function handle(VoterDocxParser $parser): int
    {
        $path = (string) $this->argument('file');
        if (!is_file($path)) {
            $this->error('File not found: ' . $path);
            return self::FAILURE;
        }

        $result = $parser->parse($path);
        $this->table(['Field', 'Value'], [
            ['Province', $result['metadata']['province'] ?? '-'],
            ['Municipality', $result['metadata']['municipality'] ?? '-'],
            ['Barangay', $result['metadata']['barangay'] ?? '-'],
            ['Declared total', $result['declared_total'] ?? '-'],
            ['Parsed unique records', count($result['records'])],
            ['Recovered outside table', $result['diagnostics']['recovered_outside_table'] ?? 0],
            ['Merged Word table rows', $result['diagnostics']['merged_table_rows'] ?? 0],
            ['Records recovered from merged rows', $result['diagnostics']['recovered_merged_records'] ?? 0],
            ['Recovered collapsed table rows', $result['diagnostics']['recovered_collapsed_table_rows'] ?? 0],
            ['Recovered prefixed continuation rows', $result['diagnostics']['recovered_prefixed_continuation_rows'] ?? 0],
            ['Continuation rows', $result['diagnostics']['continuation_rows'] ?? 0],
            ['Duplicate source numbers', implode(', ', array_unique($result['diagnostics']['duplicate_source_numbers'] ?? [])) ?: '-'],
            ['Missing source number count', $result['diagnostics']['missing_source_number_count'] ?? 0],
            ['Missing source numbers', implode(', ', $result['diagnostics']['missing_source_numbers'] ?? []) ?: '-'],
        ]);

        return self::SUCCESS;
    }
}
