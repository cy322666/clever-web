<?php

namespace Tests\Feature\Integrations;

use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class SpreadsheetSecurityTest extends TestCase
{
    public function test_exports_cannot_overwrite_an_existing_file_outside_the_configured_disk(): void
    {
        Storage::fake('exports');
        $outside = tempnam(sys_get_temp_dir(), 'clever-excel-outside-');
        $this->assertIsString($outside);
        file_put_contents($outside, 'Original outside file');
        $export = new class implements FromArray {
            public function array(): array
            {
                return [['Safe exported value']];
            }
        };

        try {
            $this->assertTrue(Excel::store($export, $outside, 'exports', ExcelFormat::CSV));
            $this->assertSame('Original outside file', file_get_contents($outside));
            Storage::disk('exports')->assertExists($outside);
        } finally {
            unlink($outside);
        }
    }

    public function test_regular_workbooks_remain_readable_through_the_excel_import_reader(): void
    {
        $path = $this->workbook();

        try {
            $this->assertSame([[['Safe spreadsheet']]], Excel::toArray(new class {}, $path, null, ExcelFormat::XLSX));
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('entityEncodings')]
    public function test_excel_import_rejects_entity_declarations_before_parsing(bool $encoded): void
    {
        $path = $this->workbook();

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertIsString($xml);

            // A tiny unused entity exercises the scanner without external access or expansion.
            $marker = $encoded ? implode("\x1b(B", str_split('<!DOCTYPE')) : '<!DOCTYPE';
            $declaration = $marker.' worksheet [<!ENTITY audit "safe">]>';
            if ($encoded) {
                $xml = str_replace('encoding="UTF-8"', 'encoding="ISO-2022-JP"', $xml);
            }
            $headerEnd = strpos($xml, '?>') + 2;
            $xml = substr($xml, 0, $headerEnd).$declaration.substr($xml, $headerEnd);
            $this->assertTrue($zip->addFromString('xl/worksheets/sheet1.xml', $xml));
            $this->assertTrue($zip->close());

            $this->expectException(ReaderException::class);
            $this->expectExceptionMessage('Detected use of ENTITY in XML');
            Excel::toArray(new class {}, $path, null, ExcelFormat::XLSX);
        } finally {
            unlink($path);
        }
    }

    public static function entityEncodings(): array
    {
        return ['plain declaration' => [false], 'ISO-2022 encoding bypass' => [true]];
    }

    private function workbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'clever-excel-security-');
        $this->assertIsString($path);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Safe spreadsheet');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
