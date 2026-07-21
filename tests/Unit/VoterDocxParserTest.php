<?php

namespace Tests\Unit;

use App\Services\Bow\VoterImport\VoterDocxParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class VoterDocxParserTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_it_splits_multiple_voters_stored_in_one_word_table_row(): void
    {
        $path = $this->createDocx([
            [
                ['1', '', '2'],
                ["O'NEIL, ANA MARIE", '', 'DELA CRUZ, JOSE'],
                ['PUROK SAN ROQUE', '', "SITIO D'ANGELO"],
                ['01/01/1990', '', '', '02/02/1992'],
                ['F', '', '', 'M'],
                ['0001A', '', '0002B'],
            ],
        ], 2, "D'LOTILLA");

        $result = (new VoterDocxParser())->parse($path);

        $this->assertCount(2, $result['records']);
        $this->assertSame(1, $result['records'][0]['source_record_no']);
        $this->assertSame("O'NEIL, ANA MARIE", $result['records'][0]['raw_name']);
        $this->assertSame('PUROK SAN ROQUE', $result['records'][0]['raw_address']);
        $this->assertSame('01/01/1990', $result['records'][0]['raw_birthdate']);
        $this->assertSame('F', $result['records'][0]['raw_sex']);
        $this->assertSame('0001A', $result['records'][0]['raw_precinct']);
        $this->assertSame(2, $result['records'][1]['source_record_no']);
        $this->assertSame('DELA CRUZ, JOSE', $result['records'][1]['raw_name']);
        $this->assertSame("SITIO D'ANGELO", $result['records'][1]['raw_address']);
        $this->assertSame(1, $result['diagnostics']['merged_table_rows']);
        $this->assertSame(2, $result['diagnostics']['recovered_merged_records']);
        $this->assertSame(0, $result['diagnostics']['missing_source_number_count']);
        $this->assertTrue($result['diagnostics']['declared_total_matches']);
    }

    public function test_it_preserves_standard_single_voter_rows(): void
    {
        $path = $this->createDocx([
            [['1'], ['ABADA, ANA MARIE'], ['PUROK 1'], ['03/03/1993'], ['F'], ['0001A']],
            [['2'], ['BASILIO, JUAN'], ['PUROK 2'], ['04/04/1994'], ['M'], ['0002B']],
        ], 2, 'NEW PANGASINAN');

        $result = (new VoterDocxParser())->parse($path);

        $this->assertCount(2, $result['records']);
        $this->assertSame('ABADA, ANA MARIE', $result['records'][0]['raw_name']);
        $this->assertSame('BASILIO, JUAN', $result['records'][1]['raw_name']);
        $this->assertSame(0, $result['diagnostics']['merged_table_rows']);
        $this->assertSame(0, $result['diagnostics']['missing_source_number_count']);
    }

    public function test_it_recovers_a_voter_row_collapsed_into_one_tab_separated_cell(): void
    {
        $path = $this->createDocx([
            [
                ['tabs' => ['1', "O'NEIL, ANA MARIE", 'PUROK SAN ROQUE', '01/01/1990', 'F', '0001A']],
            ],
        ], 1, 'KALAWAG II');

        $result = (new VoterDocxParser())->parse($path);

        $this->assertCount(1, $result['records']);
        $this->assertSame(1, $result['records'][0]['source_record_no']);
        $this->assertSame("O'NEIL, ANA MARIE", $result['records'][0]['raw_name']);
        $this->assertSame('PUROK SAN ROQUE', $result['records'][0]['raw_address']);
        $this->assertSame('01/01/1990', $result['records'][0]['raw_birthdate']);
        $this->assertSame('F', $result['records'][0]['raw_sex']);
        $this->assertSame('0001A', $result['records'][0]['raw_precinct']);
        $this->assertSame(1, $result['diagnostics']['recovered_collapsed_table_rows']);
        $this->assertSame(0, $result['diagnostics']['missing_source_number_count']);
    }

    public function test_it_recovers_a_prefixed_name_continuation_and_the_following_voter(): void
    {
        $path = $this->createDocx([
            [['1'], ['DOE, JANE MARIE'], ['PUROK 1'], ['01/01/1990'], ['F'], ['0001A']],
            [
                ['tabs' => ['SMITH', '2', 'ROE, JOHN PAUL']],
                ['PUROK 2'],
                ['02/02/1992'],
                ['M'],
                ['0002B'],
            ],
        ], 2, 'KENRAM');

        $result = (new VoterDocxParser())->parse($path);

        $this->assertCount(2, $result['records']);
        $this->assertSame('DOE, JANE MARIE SMITH', $result['records'][0]['raw_name']);
        $this->assertSame('ROE, JOHN PAUL', $result['records'][1]['raw_name']);
        $this->assertSame(1, $result['diagnostics']['recovered_prefixed_continuation_rows']);
        $this->assertSame(1, $result['diagnostics']['continuation_rows']);
        $this->assertSame(0, $result['diagnostics']['missing_source_number_count']);
    }

    public function test_it_rejects_an_unrecoverable_oversized_source_number_without_expanding_the_range(): void
    {
        $path = $this->createDocx([
            [['1,0001,001'], ['BROKEN, ROW'], ['PUROK 1'], ['01/01/1990'], ['F'], ['0001A']],
        ], 2, 'TEST BARANGAY');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A malformed Word table row produced voter number');

        (new VoterDocxParser())->parse($path);
    }

    private function createDocx(array $recordRows, int $declaredTotal, string $barangay): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . "Voter list D'ANGELO " . bin2hex(random_bytes(4)) . '.docx';
        $this->temporaryFiles[] = $path;

        $headerRow = [
            ['No.'],
            ["Voter's Name"],
            ['Address'],
            ['Birthday'],
            ['Sex'],
            ['Precinct'],
        ];
        $rows = array_merge([$headerRow], $recordRows);
        $tableXml = implode('', array_map(fn ($row) => $this->rowXml($row), $rows));
        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:tbl>' . $tableXml . '</w:tbl>'
            . '<w:p><w:r><w:t>TOTAL NUMBER OF RECORDS: ' . $declaredTotal . '</w:t></w:r></w:p>'
            . '</w:body></w:document>';
        $headerText = "PROVINCE: SULTAN KUDARAT CITY / MUNICIPALITY: ISULAN BARANGAY: {$barangay}";
        $headerXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:p><w:r><w:t>' . $this->xml($headerText) . '</w:t></w:r></w:p></w:hdr>';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/header1.xml', $headerXml);
        $zip->close();

        return $path;
    }

    private function rowXml(array $cells): string
    {
        $cellXml = '';
        foreach ($cells as $paragraphs) {
            if (isset($paragraphs['tabs'])) {
                $runs = [];
                foreach ($paragraphs['tabs'] as $value) {
                    $runs[] = '<w:r><w:t>' . $this->xml($value) . '</w:t></w:r>';
                }
                $paragraphXml = '<w:p>' . implode('<w:r><w:tab/></w:r>', $runs) . '</w:p>';
                $cellXml .= '<w:tc>' . $paragraphXml . '</w:tc>';
                continue;
            }

            $paragraphXml = '';
            foreach ($paragraphs as $paragraph) {
                $paragraphXml .= $paragraph === ''
                    ? '<w:p/>'
                    : '<w:p><w:r><w:t>' . $this->xml($paragraph) . '</w:t></w:r></w:p>';
            }
            $cellXml .= '<w:tc>' . $paragraphXml . '</w:tc>';
        }

        return '<w:tr>' . $cellXml . '</w:tr>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
