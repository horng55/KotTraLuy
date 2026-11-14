<?php

namespace Tests\Unit;

use App\Services\FinancialExcelService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class FinancialExcelServiceTest extends TestCase
{
    public function test_it_creates_a_four_sheet_xlsx_workbook(): void
    {
        $path = (new FinancialExcelService())->create([
            'business' => ['name' => 'Test workspace'], 'from' => '2026-01-01', 'to' => '2026-11-13', 'currency' => 'USD', 'generated_at' => date('c'),
            'statement_status' => ['profit_and_loss' => 'recorded_activity', 'balance_sheet' => 'incomplete', 'cash_flow' => 'incomplete'],
            'profit_and_loss' => ['net_revenue' => 100, 'net_profit' => 25], 'balance_sheet' => null, 'cash_flow' => null,
        ], 'en');
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path));
        self::assertSame(9, $zip->numFiles);
        self::assertNotFalse($zip->locateName('xl/worksheets/sheet4.xml'));
        self::assertStringContainsString('Profit &amp; Loss', $zip->getFromName('xl/workbook.xml'));
        self::assertStringContainsString('state="frozen"', $zip->getFromName('xl/worksheets/sheet1.xml'));
        self::assertStringContainsString('mergeCell ref="A1:F1"', $zip->getFromName('xl/worksheets/sheet1.xml'));
        self::assertStringContainsString('Noto Sans Khmer', $zip->getFromName('xl/styles.xml'));
        self::assertStringNotContainsString('T14:18:13', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($path);
    }

    public function test_khmer_workbook_uses_localized_limitations(): void
    {
        $path = (new FinancialExcelService())->create([
            'business' => ['name' => 'សាកល្បង'], 'from' => '2026-01-01', 'to' => '2026-11-13', 'currency' => 'KHR',
            'statement_status' => ['profit_and_loss' => 'incomplete', 'balance_sheet' => 'incomplete', 'cash_flow' => 'incomplete'],
            'limitation_codes' => ['incomplete_costs'], 'profit_and_loss' => ['net_profit' => null], 'balance_sheet' => null, 'cash_flow' => null,
        ], 'km');
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($path));
        self::assertStringContainsString('ថ្លៃដើម', $zip->getFromName('xl/worksheets/sheet1.xml'));
        self::assertStringContainsString('Noto Sans Khmer', $zip->getFromName('xl/styles.xml'));
        $zip->close();
        @unlink($path);
    }
}
