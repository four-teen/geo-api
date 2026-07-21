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
    private const MAX_SOURCE_NUMBER_WITHOUT_DECLARED_TOTAL = 100000;
    private const MAX_REPORTED_MISSING_SOURCE_NUMBERS = 1000;

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
                'missing_source_number_count' => 0,
                'missing_source_numbers_truncated' => false,
                'continuation_rows' => 0,
                'recovered_outside_table' => 0,
                'merged_table_rows' => 0,
                'recovered_merged_records' => 0,
                'recovered_collapsed_table_rows' => 0,
                'recovered_prefixed_continuation_rows' => 0,
                'ignored_rows' => 0,
            ];

            $declaredTotal = $this->declaredTotal($document);
            $this->parseTableRows($document, $records, $diagnostics);
            $this->parseOrphanParagraphRecords($document, $records, $diagnostics);
            ksort($records, SORT_NUMERIC);

            if ($records === []) {
                throw new RuntimeException('No voter records were found in the expected Word table format.');
            }

            $numbers = array_map('intval', array_keys($records));
            $maximum = max($numbers);
            $reasonableMaximum = $declaredTotal !== null
                ? max($declaredTotal + 100, (int) ceil($declaredTotal * 1.1))
                : self::MAX_SOURCE_NUMBER_WITHOUT_DECLARED_TOTAL;
            if ($maximum > $reasonableMaximum) {
                throw new RuntimeException(
                    "A malformed Word table row produced voter number {$maximum}. "
                    . 'The row may contain multiple voters that could not be separated safely.'
                );
            }

            $present = array_fill_keys($numbers, true);
            $expectedMaximum = $declaredTotal ?? $maximum;
            for ($number = 1; $number <= $expectedMaximum; $number++) {
                if (!isset($present[$number])) {
                    $diagnostics['missing_source_number_count']++;
                    if (count($diagnostics['missing_source_numbers']) < self::MAX_REPORTED_MISSING_SOURCE_NUMBERS) {
                        $diagnostics['missing_source_numbers'][] = $number;
                    } else {
                        $diagnostics['missing_source_numbers_truncated'] = true;
                    }
                }
            }

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
            $paragraphCells = [];
            $tabCells = [];
            foreach ($xpath->query('./w:tc', $row) ?: [] as $cell) {
                $cells[] = $this->nodeText($xpath, $cell);
                $paragraphCells[] = $this->cellParagraphs($xpath, $cell);
                $tabCells[] = $this->cellTabValues($xpath, $cell);
            }

            if ($cells === [] || $this->isHeaderRow($cells)) {
                continue;
            }

            $mergedRecords = $this->mergedTableRecords($paragraphCells);
            if ($mergedRecords !== null) {
                foreach ($mergedRecords as $record) {
                    $lastSourceNumber = $record['source_record_no'];
                    $this->putRecord($records, $record, $diagnostics);
                }
                $diagnostics['merged_table_rows']++;
                $diagnostics['recovered_merged_records'] += count($mergedRecords);
                continue;
            }

            $collapsedRecord = $this->collapsedTableRecord($cells, $tabCells);
            if ($collapsedRecord !== null) {
                $lastSourceNumber = $collapsedRecord['source_record_no'];
                $this->putRecord($records, $collapsedRecord, $diagnostics);
                $diagnostics['recovered_collapsed_table_rows']++;
                continue;
            }

            $prefixedRecord = $this->prefixedContinuationTableRecord($cells, $tabCells);
            if ($prefixedRecord !== null) {
                if ($lastSourceNumber !== null && isset($records[$lastSourceNumber])) {
                    $records[$lastSourceNumber]['raw_name'] = trim(
                        $records[$lastSourceNumber]['raw_name'] . ' ' . $prefixedRecord['name_continuation']
                    );
                    $diagnostics['continuation_rows']++;
                }
                $lastSourceNumber = $prefixedRecord['record']['source_record_no'];
                $this->putRecord($records, $prefixedRecord['record'], $diagnostics);
                $diagnostics['recovered_prefixed_continuation_rows']++;
                continue;
            }

            if (count($cells) < 2) {
                $diagnostics['ignored_rows']++;
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

    private function mergedTableRecords(array $paragraphCells): ?array
    {
        if (count($paragraphCells) < 5) {
            return null;
        }

        if (count($paragraphCells) >= 6) {
            $numberValues = $this->paragraphValues($paragraphCells[0], null);
            if (count($numberValues) < 2) {
                return null;
            }

            $numbers = array_map(fn ($value) => $this->sourceNumber($value), $numberValues);
            if (in_array(null, $numbers, true) || !$this->areConsecutive($numbers)) {
                return null;
            }

            $recordCount = count($numbers);
            $names = $this->paragraphValues($paragraphCells[1], $recordCount);
            $addresses = $this->paragraphValues($paragraphCells[2], $recordCount);
            $birthdays = $this->paragraphValues($paragraphCells[count($paragraphCells) - 3], $recordCount);
            $sexes = $this->paragraphValues($paragraphCells[count($paragraphCells) - 2], $recordCount);
            $precincts = $this->paragraphValues($paragraphCells[count($paragraphCells) - 1], $recordCount);

            if (in_array(null, [$names, $addresses, $birthdays, $sexes, $precincts], true)) {
                return null;
            }

            $records = [];
            foreach ($numbers as $index => $number) {
                $records[] = $this->record(
                    $number,
                    $names[$index],
                    $addresses[$index],
                    $birthdays[$index],
                    $sexes[$index],
                    $precincts[$index]
                );
            }

            return $records;
        }

        $firstCellValues = $this->paragraphValues($paragraphCells[0], null);
        if (count($firstCellValues) < 2) {
            return null;
        }

        $numbers = [];
        $names = [];
        foreach ($firstCellValues as $value) {
            if (!preg_match('/^([0-9]{1,3}(?:,[0-9]{3})*|[0-9]+)\s+(.+)$/u', $value, $match)) {
                return null;
            }
            $number = $this->sourceNumber($match[1]);
            if ($number === null) {
                return null;
            }
            $numbers[] = $number;
            $names[] = $match[2];
        }
        if (!$this->areConsecutive($numbers)) {
            return null;
        }

        $recordCount = count($numbers);
        $addresses = $this->paragraphValues($paragraphCells[1], $recordCount);
        $birthdays = $this->paragraphValues($paragraphCells[count($paragraphCells) - 3], $recordCount);
        $sexes = $this->paragraphValues($paragraphCells[count($paragraphCells) - 2], $recordCount);
        $precincts = $this->paragraphValues($paragraphCells[count($paragraphCells) - 1], $recordCount);
        if (in_array(null, [$addresses, $birthdays, $sexes, $precincts], true)) {
            return null;
        }

        $records = [];
        foreach ($numbers as $index => $number) {
            $records[] = $this->record(
                $number,
                $names[$index],
                $addresses[$index],
                $birthdays[$index],
                $sexes[$index],
                $precincts[$index]
            );
        }

        return $records;
    }

    private function collapsedTableRecord(array $cells, array $tabCells): ?array
    {
        if (count($cells) === 1) {
            $parts = $tabCells[0] ?? [];
            if (count($parts) < 6) {
                return null;
            }

            $number = $this->sourceNumber($parts[0]);
            $precinct = $this->precinctFromSegment($parts[5]);
            if (
                $number === null
                || $parts[1] === ''
                || $parts[2] === ''
                || !preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $parts[3])
                || !preg_match('/^[MF]$/i', $parts[4])
                || $precinct === null
            ) {
                return null;
            }

            return $this->record($number, $parts[1], $parts[2], $parts[3], $parts[4], $precinct);
        }

        if (count($cells) >= 3 && count($cells) < 5) {
            $number = $this->sourceNumber($cells[0]);
            $parts = $tabCells[count($tabCells) - 1] ?? [];
            $precinct = isset($parts[3]) ? $this->precinctFromSegment($parts[3]) : null;
            if (
                $number === null
                || $cells[1] === ''
                || count($parts) < 4
                || !preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $parts[1])
                || !preg_match('/^[MF]$/i', $parts[2])
                || $precinct === null
            ) {
                return null;
            }

            return $this->record($number, $cells[1], $parts[0], $parts[1], $parts[2], $precinct);
        }

        return null;
    }

    private function prefixedContinuationTableRecord(array $cells, array $tabCells): ?array
    {
        if (count($cells) < 5) {
            return null;
        }

        $firstCellParts = $tabCells[0] ?? [];
        if (count($firstCellParts) !== 3) {
            return null;
        }

        [$nameContinuation, $sourceNumber, $name] = $firstCellParts;
        $number = $this->sourceNumber($sourceNumber);
        $birthday = $cells[count($cells) - 3];
        $sex = $cells[count($cells) - 2];
        $precinct = $this->precinctFromSegment($cells[count($cells) - 1]);
        if (
            $nameContinuation === ''
            || $number === null
            || $name === ''
            || $cells[1] === ''
            || !preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $birthday)
            || !preg_match('/^[MF]$/i', $sex)
            || $precinct === null
        ) {
            return null;
        }

        return [
            'name_continuation' => $nameContinuation,
            'record' => $this->record($number, $name, $cells[1], $birthday, $sex, $precinct),
        ];
    }

    private function cellParagraphs(DOMXPath $xpath, DOMNode $cell): array
    {
        $paragraphs = [];
        foreach ($xpath->query('./w:p', $cell) ?: [] as $paragraph) {
            $paragraphs[] = $this->nodeText($xpath, $paragraph);
        }

        return $paragraphs;
    }

    private function cellTabValues(DOMXPath $xpath, DOMNode $cell): array
    {
        $values = [];
        foreach ($xpath->query('./w:p', $cell) ?: [] as $paragraph) {
            $current = '';
            foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph) ?: [] as $node) {
                if ($node instanceof DOMElement && $node->localName === 't') {
                    $current .= $node->textContent;
                    continue;
                }

                $value = $this->clean($current);
                if ($value !== '') {
                    $values[] = $value;
                }
                $current = '';
            }

            $value = $this->clean($current);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function paragraphValues(array $paragraphs, ?int $expectedCount): ?array
    {
        $values = array_values(array_filter(
            array_map(fn ($value) => $this->clean($value), $paragraphs),
            fn ($value) => $value !== ''
        ));

        if ($expectedCount === null || count($values) === $expectedCount) {
            return $values;
        }

        $groups = [];
        $current = [];
        foreach ($paragraphs as $paragraph) {
            $value = $this->clean($paragraph);
            if ($value === '') {
                if ($current !== []) {
                    $groups[] = implode(' ', $current);
                    $current = [];
                }
                continue;
            }
            $current[] = $value;
        }
        if ($current !== []) {
            $groups[] = implode(' ', $current);
        }

        return count($groups) === $expectedCount ? $groups : null;
    }

    private function sourceNumber(string $value): ?int
    {
        $value = $this->clean($value);
        if (!preg_match('/^(?:[0-9]{1,3}(?:,[0-9]{3})*|[0-9]+)$/', $value)) {
            return null;
        }

        $digits = str_replace(',', '', $value);
        if (strlen($digits) > 9) {
            return null;
        }

        $number = (int) $digits;
        return $number > 0 ? $number : null;
    }

    private function precinctFromSegment(string $value): ?string
    {
        $value = strtoupper($this->clean($value));
        if (!preg_match('/^([0-9]{3,5}[A-Z]?)\b/', $value, $match)) {
            return null;
        }

        return $match[1];
    }

    private function areConsecutive(array $numbers): bool
    {
        if (count($numbers) < 2) {
            return false;
        }

        for ($index = 1; $index < count($numbers); $index++) {
            if ($numbers[$index] !== $numbers[$index - 1] + 1) {
                return false;
            }
        }

        return true;
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
