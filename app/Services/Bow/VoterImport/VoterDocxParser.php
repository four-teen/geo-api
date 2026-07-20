<?php

namespace App\Services\Bow\VoterImport;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class VoterDocxParser
{
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function parse(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The uploaded file is not a readable DOCX archive.');
        }

        try {
            $documentXml = $zip->getFromName('word/document.xml');
            if ($documentXml === false) {
                throw new RuntimeException('The DOCX file does not contain word/document.xml.');
            }

            $document = $this->loadXml($documentXml);
            $metadata = $this->readMetadata($zip);
            $records = [];
            $diagnostics = [
                'duplicate_source_numbers' => [],
                'missing_source_numbers' => [],
                'continuation_rows' => 0,
                'recovered_outside_table' => 0,
                'ignored_rows' => 0,
            ];

            $this->parseTableRows($document, $records, $diagnostics);
            $this->parseOrphanParagraphRecords($document, $records, $diagnostics);
            ksort($records, SORT_NUMERIC);

            if ($records === []) {
                throw new RuntimeException('No voter records were found in the expected Word table format.');
            }

            $numbers = array_map('intval', array_keys($records));
            $minimum = min($numbers);
            $maximum = max($numbers);
            $present = array_fill_keys($numbers, true);
            for ($number = $minimum; $number <= $maximum; $number++) {
                if (!isset($present[$number])) {
                    $diagnostics['missing_source_numbers'][] = $number;
                }
            }

            $declaredTotal = $this->declaredTotal($document);
            $diagnostics['declared_total_matches'] = $declaredTotal === null || $declaredTotal === count($records);

            return [
                'metadata' => $metadata,
                'declared_total' => $declaredTotal,
                'records' => array_values($records),
                'diagnostics' => $diagnostics,
            ];
        } finally {
            $zip->close();
        }
    }

    private function parseTableRows(DOMDocument $document, array &$records, array &$diagnostics): void
    {
        $xpath = $this->xpath($document);
        $lastSourceNumber = null;

        foreach ($xpath->query('//w:tbl//w:tr') ?: [] as $row) {
            $cells = [];
            foreach ($xpath->query('./w:tc', $row) ?: [] as $cell) {
                $cells[] = $this->nodeText($xpath, $cell);
            }

            if (count($cells) < 2 || $this->isHeaderRow($cells)) {
                continue;
            }

            $record = $this->tableRecord($cells);
            if ($record !== null) {
                $lastSourceNumber = $record['source_record_no'];
                $this->putRecord($records, $record, $diagnostics);
                continue;
            }

            $continuation = $this->continuationParts($cells);
            if ($lastSourceNumber !== null && $continuation !== null) {
                [$nameContinuation, $addressContinuation] = $continuation;
                if ($nameContinuation !== '') {
                    $records[$lastSourceNumber]['raw_name'] = trim($records[$lastSourceNumber]['raw_name'] . ' ' . $nameContinuation);
                }
                if ($addressContinuation !== '') {
                    $records[$lastSourceNumber]['raw_address'] = trim($records[$lastSourceNumber]['raw_address'] . ' ' . $addressContinuation);
                }
                $diagnostics['continuation_rows']++;
                continue;
            }

            $diagnostics['ignored_rows']++;
        }
    }

    private function tableRecord(array $cells): ?array
    {
        if (count($cells) >= 6 && preg_match('/^\s*([0-9,]+)\s*$/', $cells[0], $numberMatch)) {
            $number = (int) str_replace(',', '', $numberMatch[1]);
            return $this->record($number, $cells[1], $cells[2], $cells[count($cells) - 3], $cells[count($cells) - 2], $cells[count($cells) - 1]);
        }

        $first = preg_replace('/\s+/', ' ', trim((string) ($cells[0] ?? ''))) ?? '';
        if (count($cells) >= 5 && preg_match('/^([0-9,]+)\s+(.+)$/u', $first, $match)) {
            $number = (int) str_replace(',', '', $match[1]);
            return $this->record($number, $match[2], $cells[1], $cells[count($cells) - 3], $cells[count($cells) - 2], $cells[count($cells) - 1]);
        }

        return null;
    }

    private function parseOrphanParagraphRecords(DOMDocument $document, array &$records, array &$diagnostics): void
    {
        $xpath = $this->xpath($document);
        $paragraphs = [];
        foreach ($xpath->query('//w:body/w:p') ?: [] as $paragraph) {
            $text = trim($this->nodeText($xpath, $paragraph));
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        for ($index = 0; $index + 4 < count($paragraphs); $index++) {
            if (!preg_match('/^([0-9]{1,3}(?:,[0-9]{3})*)$/', $paragraphs[$index], $numberMatch)) {
                continue;
            }
            if (!preg_match('/^(\d{2}\/\d{2}\/\d{4})\s*([MF])$/i', $paragraphs[$index + 3], $dateSexMatch)) {
                continue;
            }
            if (!preg_match('/^[0-9]{3,5}[A-Z]?$/i', preg_replace('/\s+/', '', $paragraphs[$index + 4]) ?? '')) {
                continue;
            }

            $number = (int) str_replace(',', '', $numberMatch[1]);
            $record = $this->record(
                $number,
                $paragraphs[$index + 1],
                $paragraphs[$index + 2],
                $dateSexMatch[1],
                strtoupper($dateSexMatch[2]),
                $paragraphs[$index + 4]
            );
            $alreadyPresent = isset($records[$number]);
            $this->putRecord($records, $record, $diagnostics);
            if (!$alreadyPresent) {
                $diagnostics['recovered_outside_table']++;
            }
            $index += 4;
        }
    }

    private function record(int $number, mixed $name, mixed $address, mixed $birthday, mixed $sex, mixed $precinct): array
    {
        return [
            'source_record_no' => $number,
            'raw_name' => $this->clean($name),
            'raw_address' => $this->clean($address),
            'raw_birthdate' => $this->clean($birthday),
            'raw_sex' => strtoupper($this->clean($sex)),
            'raw_precinct' => strtoupper($this->clean($precinct)),
        ];
    }

    private function putRecord(array &$records, array $record, array &$diagnostics): void
    {
        $number = $record['source_record_no'];
        if (!isset($records[$number])) {
            $records[$number] = $record;
            return;
        }

        $diagnostics['duplicate_source_numbers'][] = $number;
        if ($this->completeness($record) > $this->completeness($records[$number])) {
            $records[$number] = $record;
        }
    }

    private function completeness(array $record): int
    {
        return count(array_filter([
            $record['raw_name'] ?? '',
            $record['raw_address'] ?? '',
            $record['raw_birthdate'] ?? '',
            $record['raw_sex'] ?? '',
            $record['raw_precinct'] ?? '',
        ], fn ($value) => trim((string) $value) !== ''));
    }

    private function continuationParts(array $cells): ?array
    {
        $tail = array_slice($cells, -3);
        if (count(array_filter($tail, fn ($value) => trim((string) $value) !== '')) !== 0) {
            return null;
        }

        if (count($cells) >= 6) {
            $name = trim((string) ($cells[1] ?? ''));
            $address = trim((string) ($cells[2] ?? ''));
        } else {
            $name = trim((string) ($cells[0] ?? ''));
            $address = trim((string) ($cells[1] ?? ''));
        }

        if ($name === '' && $address === '') {
            return null;
        }
        if (preg_match('/^\s*[0-9,]+\b/', $name)) {
            return null;
        }

        return [$name, $address];
    }

    private function isHeaderRow(array $cells): bool
    {
        $text = strtoupper(implode(' ', $cells));
        return str_contains($text, "VOTER'S NAME") && str_contains($text, 'PRECINCT');
    }

    private function readMetadata(ZipArchive $zip): array
    {
        $text = '';
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (!preg_match('#^word/header\d+\.xml$#', $name)) {
                continue;
            }
            $xml = $zip->getFromIndex($index);
            if ($xml === false) {
                continue;
            }
            $document = $this->loadXml($xml);
            $xpath = $this->xpath($document);
            $parts = [];
            foreach ($xpath->query('//w:t') ?: [] as $node) {
                $parts[] = trim($node->textContent);
            }
            $text .= ' ' . implode(' ', array_filter($parts, fn ($part) => $part !== ''));
        }

        $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        $province = $this->metadataValue($text, '/PROVINCE\s*:\s*(.+?)\s+CITY\s*\/\s*MUNICIPALITY\s*:/i');
        $municipality = $this->metadataValue($text, '/MUNICIPALITY\s*:\s*(.+?)\s+BARANGAY\s*:/i');
        $barangay = $this->metadataValue($text, '/BARANGAY\s*:\s*(.+?)(?=\s+PROVINCE\s*:|$)/i');

        return [
            'province' => $province,
            'municipality' => $municipality,
            'barangay' => $barangay,
        ];
    }

    private function metadataValue(string $text, string $pattern): ?string
    {
        if (!preg_match($pattern, $text, $match)) {
            return null;
        }

        $value = $this->clean($match[1]);
        return $value === '' ? null : strtoupper($value);
    }

    private function declaredTotal(DOMDocument $document): ?int
    {
        $text = $this->nodeText($this->xpath($document), $document);
        if (!preg_match('/TOTAL\s+NUMBER\s+OF\s+RECORDS\s*:\s*([0-9,]+)/i', $text, $match)) {
            return null;
        }

        return (int) str_replace(',', '', $match[1]);
    }

    private function loadXml(string $xml): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('The DOCX contains invalid Word XML.');
        }

        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NS);
        return $xpath;
    }

    private function nodeText(DOMXPath $xpath, DOMNode $node): string
    {
        $parts = [];
        foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $node) ?: [] as $child) {
            if ($child instanceof DOMElement && $child->localName === 't') {
                $parts[] = $child->textContent;
            } else {
                $parts[] = ' ';
            }
        }

        return $this->clean(implode('', $parts));
    }

    private function clean(mixed $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
        return trim($value);
    }
}
