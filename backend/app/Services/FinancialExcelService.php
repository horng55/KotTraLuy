<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use ZipArchive;

class FinancialExcelService
{
    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const DISCLAIMER = 'Management-prepared draft — Not audited or verified.';
    private const DATA_NOTICE = 'These statements reflect recorded workspace activity and available data only.';

    public function create(array $report, string $language = 'en'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kottraluy-report-');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create Excel workbook.');
        }

        $sheets = [
            $this->overview($report, $language),
            $this->statement('Profit & Loss', $report['profit_and_loss'] ?? null, $report, $language),
            $this->statement('Cash Flow', $report['cash_flow'] ?? null, $report, $language),
        ];

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', $this->worksheet($sheet));
        }
        $zip->close();

        return $path;
    }

    public function mime(): string
    {
        return self::MIME;
    }

    private function overview(array $report, string $language): array
    {
        $l = $this->labels($language);
        $generated = $this->formatTimestamp($report['generated_at'] ?? null, $report['business']['timezone'] ?? $this->defaultTimezone());
        $period = $this->formatDate($report['from'] ?? '') . ' – ' . $this->formatDate($report['to'] ?? '');
        $rows = [
            $this->row('title', [$l['brand'] . '  |  ' . $l['financialStatements']]),
            $this->row('subtitle', [$report['business']['name'] ?? $l['workspace'], $l['draft']]),
            $this->row('spacer', []),
            $this->row('section', [$l['reportInformation']]),
            $this->row('meta', [$l['businessName'], $report['business']['name'] ?? '']),
            $this->row('meta', [$l['reportingPeriod'], $period]),
            $this->row('meta', [$l['reportingCurrency'], $report['currency'] ?? '']),
            $this->row('meta', [$l['generatedOn'], $generated]),
            $this->row('meta', [$l['reportStatus'], $l['draft']]),
            $this->row('spacer', []),
            $this->row('section', [$l['statementAvailability']]),
            $this->row('availabilityHeader', [$l['financialStatement'], $l['availability'], $l['notes']]),
        ];

        foreach ([
            'profit_and_loss' => $l['profitLoss'],
            'cash_flow' => $l['cashFlow'],
        ] as $key => $name) {
            $status = $this->availability($report, $key);
            $rows[] = $this->row('availability:' . $status, [$name, $this->statusLabel($status, $l), $this->statusNote($status, $l)]);
        }

        $rows[] = $this->row('spacer', []);
        $rows[] = $this->row('notice', [self::DISCLAIMER]);
        $rows[] = $this->row('notice', [self::DATA_NOTICE]);
        if (!empty($report['limitations']) || !empty($report['limitation_codes'])) {
            $rows[] = $this->row('section', [$l['limitations']]);
            $limitations = !empty($report['limitation_codes'])
                ? array_map(fn ($code) => $this->limitation($code, $language), $report['limitation_codes'])
                : (array) ($report['limitations'] ?? []);
            foreach ($limitations as $limitation) {
                $rows[] = $this->row('note', ['• ' . (string) $limitation]);
            }
        }

        return $rows;
    }

    private function statement(string $title, ?array $statement, array $report, string $language): array
    {
        $l = $this->labels($language);
        $status = $this->availability($report, $this->statementKey($title));
        $displayTitle = $l[$this->titleKey($title)];
        $period = $title === 'Balance Sheet'
            ? $l['asOf'] . ' ' . $this->formatDate($report['to'] ?? '')
            : $this->formatDate($report['from'] ?? '') . ' – ' . $this->formatDate($report['to'] ?? '');

        $rows = [
            $this->row('title', [$l['brand'] . '  |  ' . $displayTitle]),
            $this->row('subtitle', [$report['business']['name'] ?? $l['workspace'], $period . '  ·  ' . ($report['currency'] ?? '')]),
            $this->row('status:' . $status, [$l['status'] . ': ' . $this->statusLabel($status, $l)]),
            $this->row('notice', [self::DISCLAIMER]),
            $this->row('notice', [self::DATA_NOTICE]),
        ];

        if (!$statement || $status === 'unavailable') {
            $rows[] = $this->row('unavailable', [$l['statementUnavailable']]);
            return $rows;
        }

        $rows[] = $this->row('header', [$l['statementItem'], $l['amount'] . ' (' . ($report['currency'] ?? '') . ')']);
        $this->flatten($statement, $rows, $l);
        return $rows;
    }

    private function flatten(array $values, array &$rows, array $labels, int $depth = 0): void
    {
        foreach ($values as $key => $value) {
            if (in_array($key, ['validation', 'as_of'], true)) {
                continue;
            }
            $label = $this->humanLabel((string) $key, $labels);
            if (is_array($value)) {
                $rows[] = $this->row('section', [str_repeat('  ', $depth) . $label]);
                $this->flatten($value, $rows, $labels, $depth + 1);
                continue;
            }
            $isTotal = in_array($key, ['net_revenue', 'gross_profit', 'total_operating_expenses', 'net_profit', 'net_change_in_cash', 'ending_cash'], true);
            $rows[] = $this->row($isTotal ? 'total' : 'data', [
                str_repeat('  ', $depth) . $label,
                is_numeric($value) ? (float) $value : ($value === null ? $labels['unavailable'] : (string) $value),
            ]);
        }
    }

    private function row(string $kind, array $cells): array
    {
        return ['kind' => $kind, 'cells' => $cells];
    }

    private function availability(array $report, string $key): string
    {
        if (!array_key_exists($key, $report) || !is_array($report[$key])) {
            return 'unavailable';
        }
        $status = $report['statement_status'][$key] ?? null;
        if ($status === 'incomplete') {
            return 'incomplete';
        }
        return $status ? 'available' : 'unavailable';
    }

    private function statementKey(string $title): string
    {
        return ['Profit & Loss' => 'profit_and_loss', 'Cash Flow' => 'cash_flow'][$title];
    }

    private function titleKey(string $title): string
    {
        return ['Profit & Loss' => 'profitLoss', 'Cash Flow' => 'cashFlow'][$title];
    }

    private function labels(string $language): array
    {
        if ($language !== 'km') {
            return [
                'brand' => 'KotTraLuy', 'financialStatements' => 'Financial Statements', 'workspace' => 'Workspace',
                'draft' => 'Draft', 'reportInformation' => 'Report information', 'businessName' => 'Business name',
                'reportingPeriod' => 'Reporting period', 'reportingCurrency' => 'Reporting currency',
                'generatedOn' => 'Generated on', 'reportStatus' => 'Report status', 'statementAvailability' => 'Statement availability',
                'financialStatement' => 'Financial statement', 'availability' => 'Availability', 'notes' => 'Notes',
                'profitLoss' => 'Profit & Loss', 'balanceSheet' => 'Balance Sheet', 'cashFlow' => 'Cash Flow',
                'limitations' => 'Data notes', 'status' => 'Status', 'asOf' => 'As of',
                'statementItem' => 'Statement item', 'amount' => 'Amount', 'statementUnavailable' => 'Statement unavailable', 'unavailable' => 'Unavailable',
                'available' => 'Available', 'incomplete' => 'Incomplete',
            ];
        }

        return [
            'brand' => 'KotTraLuy', 'financialStatements' => $this->km('របាយការណ៍ហិរញ្ញវត្ថុ'),
            'workspace' => $this->km('កន្លែងធ្វើការ'), 'draft' => $this->km('ព្រាង'),
            'reportInformation' => $this->km('ព័ត៌មានរបាយការណ៍'), 'businessName' => $this->km('ឈ្មោះអាជីវកម្ម'),
            'reportingPeriod' => $this->km('រយៈពេលរបាយការណ៍'), 'reportingCurrency' => $this->km('រូបិយប័ណ្ណរបាយការណ៍'),
            'generatedOn' => $this->km('បង្កើតនៅ'), 'reportStatus' => $this->km('ស្ថានភាពរបាយការណ៍'),
            'statementAvailability' => $this->km('ភាពមានរបាយការណ៍'), 'financialStatement' => $this->km('របាយការណ៍ហិរញ្ញវត្ថុ'),
            'availability' => $this->km('ភាពមាន'), 'notes' => $this->km('កំណត់សម្គាល់'),
            'profitLoss' => $this->km('ចំណេញ និង ខាត'), 'balanceSheet' => $this->km('តារាងតុល្យការ'),
            'cashFlow' => $this->km('លំហូរសាច់ប្រាក់'), 'limitations' => $this->km('កំណត់សម្គាល់ទិន្នន័យ'),
            'status' => $this->km('ស្ថានភាព'), 'asOf' => $this->km('គិតត្រឹម'),
            'statementItem' => $this->km('ធាតុរបាយការណ៍'), 'amount' => $this->km('ចំនួនទឹកប្រាក់'), 'statementUnavailable' => $this->km('របាយការណ៍មិនមានទិន្នន័យ'),
            'unavailable' => $this->km('មិនមានទិន្នន័យ'), 'available' => $this->km('មានទិន្នន័យ'),
            'incomplete' => $this->km('មិនពេញលេញ'),
        ];
    }

    private function limitation(string $code, string $language): string
    {
        $english = [
            'recorded_activity_only' => 'Statements describe recorded activity only; sales returns/refunds have no separate ledger.',
            'unrecorded_accounts' => 'Opening balances, loans, owner transactions, equipment and bank transfers are not recorded; a complete balance sheet cannot be produced.',
            'partial_cash_flow' => 'Customer receipts are recorded, but supplier/expense payment status is unknown. Net cash flow and investing/financing totals are unavailable.',
            'legacy_cost_currency' => 'Opening/legacy unit costs use the workspace cost currency; purchases use their recorded currency. Costs are never converted.',
            'inventory_snapshot' => 'Inventory is a current quantity snapshot. Historical or future inventory valuation is unavailable.',
            'separate_currencies' => 'Other currencies exist in this period. They are excluded; no exchange rates or currency conversion are applied.',
            'incomplete_costs' => 'Some costs are missing or use another currency. COGS and profit totals are unavailable rather than estimated.',
            'incomplete_receivables' => 'Payment history is incomplete. Receivables cannot be reliably reconstructed for this cutoff.',
            'incomplete_inventory' => 'Some recorded stock quantities have no matching batch cost history. Inventory valuation is unavailable.',
        ];
        if ($language !== 'km') return $english[$code] ?? $code;
        $khmer = [
            'recorded_activity_only' => 'របាយការណ៍បង្ហាញតែសកម្មភាពដែលបានកត់ត្រា។ មិនមានបញ្ជីដាច់ដោយឡែកសម្រាប់ការបង្វិលទំនិញ ឬការសងប្រាក់ទេ។',
            'unrecorded_accounts' => 'សមតុល្យដើមគ្រា ប្រាក់កម្ចី ប្រតិបត្តិការម្ចាស់ ឧបករណ៍ និងការផ្ទេរធនាគារ មិនត្រូវបានកត់ត្រាទេ ដូច្នេះមិនអាចបង្កើតតារាងតុល្យការពេញលេញបានទេ។',
            'partial_cash_flow' => 'ប្រាក់ទទួលពីអតិថិជនត្រូវបានកត់ត្រា ប៉ុន្តែស្ថានភាពទូទាត់ចំណាយ និងអ្នកផ្គត់ផ្គង់មិនត្រូវបានកត់ត្រាទេ។',
            'legacy_cost_currency' => 'ថ្លៃដើមចាស់ប្រើរូបិយប័ណ្ណថ្លៃដើមរបស់កន្លែងធ្វើការ។ មិនមានការបម្លែងថ្លៃដើមទេ។',
            'inventory_snapshot' => 'ស្តុកជាទិន្នន័យចំនួនបច្ចុប្បន្ន។ មិនមានតម្លៃស្តុកសម្រាប់កាលបរិច្ឆេទអតីតកាល ឬអនាគតទេ។',
            'separate_currencies' => 'រូបិយប័ណ្ណផ្សេងទៀតត្រូវបានដកចេញ។ មិនមានការប្រើអត្រាប្ដូរប្រាក់ ឬការបម្លែងទេ។',
            'incomplete_costs' => 'ថ្លៃដើមមួយចំនួនមិនមាន ឬប្រើរូបិយប័ណ្ណផ្សេង។ មិនប៉ាន់ស្មានថ្លៃដើមទំនិញលក់ ឬចំណេញទេ។',
            'incomplete_receivables' => 'ប្រវត្តិការទូទាត់មិនពេញលេញ។ មិនអាចកំណត់ប្រាក់ត្រូវទទួលបានត្រឹមត្រូវទេ។',
            'incomplete_inventory' => 'ចំនួនស្តុកមួយចំនួនមិនមានប្រវត្តិថ្លៃដើមកញ្ចប់ដែលត្រូវគ្នាទេ។ មិនអាចកំណត់តម្លៃស្តុកបានទេ។',
        ];
        return $khmer[$code] ?? $code;
    }

    private function km(string $value): string
    {
        return $value;
    }

    private function humanLabel(string $key, array $labels): string
    {
        $map = [
            'sales_revenue' => 'Sales revenue', 'sales_returns' => 'Sales returns', 'sales_discounts' => 'Sales discounts',
            'net_revenue' => 'Net revenue', 'cost_of_goods_sold' => 'Cost of goods sold', 'gross_profit' => 'Gross profit',
            'operating_expenses' => 'Operating expenses', 'total_operating_expenses' => 'Total operating expenses',
            'net_profit' => 'Net profit / loss', 'assets' => 'Assets', 'cash_and_bank' => 'Cash and bank',
            'accounts_receivable' => 'Accounts receivable', 'inventory_at_cost' => 'Inventory at cost',
            'equipment_net' => 'Equipment, net', 'liabilities' => 'Liabilities', 'accounts_payable' => 'Accounts payable',
            'outstanding_loans' => 'Outstanding loans', 'equity' => 'Equity', 'owners_capital' => 'Owners capital',
            'retained_earnings_and_current_earnings' => 'Retained earnings and current earnings',
            'owner_withdrawals' => 'Owner withdrawals', 'operating' => 'Operating activities',
            'customer_receipts' => 'Customer receipts', 'supplier_payments' => 'Supplier payments',
            'operating_expenses_paid' => 'Operating expenses paid', 'investing' => 'Investing activities',
            'equipment_purchases' => 'Equipment purchases', 'equipment_disposals' => 'Equipment disposals',
            'financing' => 'Financing activities', 'loan_proceeds' => 'Loan proceeds',
            'loan_principal_repayments' => 'Loan principal repayments', 'owner_contributions' => 'Owner contributions',
            'net_change_in_cash' => 'Net change in cash', 'beginning_cash' => 'Beginning cash',
            'ending_cash' => 'Ending cash',
        ];
        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }

    private function statusLabel(string $status, array $labels): string
    {
        return $labels[$status] ?? ucfirst($status);
    }

    private function statusNote(string $status, array $labels): string
    {
        return match ($status) {
            'available' => $labels['available'],
            'incomplete' => $labels['incomplete'],
            default => $labels['unavailable'],
        };
    }

    private function formatDate(string $date): string
    {
        if (!$date) return '';
        try { return Carbon::parse($date)->format('d M Y'); } catch (\Throwable) { return $date; }
    }

    private function formatTimestamp(?string $timestamp, ?string $timezone): string
    {
        if (!$timestamp) return '';
        try { return Carbon::parse($timestamp)->setTimezone($timezone ?: $this->defaultTimezone())->format('d M Y, g:i A'); } catch (\Throwable) { return $timestamp; }
    }

    private function defaultTimezone(): string
    {
        try { return function_exists('config') ? (string) config('app.timezone', 'UTC') : 'UTC'; } catch (\Throwable) { return 'UTC'; }
    }

    private function worksheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:F' . count($rows) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols><col min="1" max="1" width="28" customWidth="1"/><col min="2" max="2" width="24" customWidth="1"/><col min="3" max="3" width="20" customWidth="1"/><col min="4" max="6" width="18" customWidth="1"/></cols><sheetData>';
        $merges = [];

        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $kind = $row['kind'];
            $height = match (true) {
                $kind === 'title' => 46,
                $kind === 'section' || $kind === 'header' || $kind === 'availabilityHeader' => 28,
                str_starts_with($kind, 'notice') || $kind === 'note' => 30,
                default => 24,
            };
            $style = $this->rowStyle($kind);
            $xml .= '<row r="' . $number . '" ht="' . $height . '" customHeight="1">';
            foreach ($row['cells'] as $column => $value) {
                $ref = $this->cellReference($column, $number);
                $isNumber = is_int($value) || is_float($value);
                $cellStyle = $isNumber ? 9 : $style;
                if (str_starts_with($kind, 'availability:')) $cellStyle = $this->availabilityStyle($kind);
                $xml .= $isNumber
                    ? '<c r="' . $ref . '" s="' . $cellStyle . '"><v>' . $this->number($value) . '</v></c>'
                    : '<c r="' . $ref . '" s="' . $cellStyle . '" t="inlineStr"><is><t xml:space="preserve">' . $this->escape((string) $value) . '</t></is></c>';
            }
            $xml .= '</row>';

            if (in_array($kind, ['title', 'subtitle', 'section', 'notice', 'note', 'unavailable'], true)
                || str_starts_with($kind, 'status:')) {
                $merges[] = 'A' . $number . ':F' . $number;
            } elseif ($kind === 'meta') {
                $merges[] = 'B' . $number . ':F' . $number;
            } elseif (str_starts_with($kind, 'availability:')) {
                $merges[] = 'C' . $number . ':F' . $number;
            }
        }

        $xml .= '</sheetData>';
        if ($merges) {
            $xml .= '<mergeCells count="' . count($merges) . '">' . implode('', array_map(fn ($range) => '<mergeCell ref="' . $range . '"/>', $merges)) . '</mergeCells>';
        }
        return $xml . '<pageMargins left="0.3" right="0.3" top="0.45" bottom="0.45" header="0.2" footer="0.2"/><pageSetup orientation="portrait" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    private function rowStyle(string $kind): int
    {
        return match (true) {
            $kind === 'title' => 1,
            $kind === 'subtitle' => 2,
            $kind === 'section' => 3,
            $kind === 'header' || $kind === 'availabilityHeader' => 4,
            $kind === 'meta' => 5,
            $kind === 'notice' || $kind === 'note' => 6,
            $kind === 'unavailable' => 8,
            str_starts_with($kind, 'status:') => 7,
            $kind === 'total' => 10,
            default => 0,
        };
    }

    private function availabilityStyle(string $kind): int
    {
        return match (substr($kind, 13)) {
            'available' => 11,
            'incomplete' => 12,
            default => 13,
        };
    }

    private function cellReference(int $column, int $row): string
    {
        $letters = '';
        do { $letters = chr(65 + ($column % 26)) . $letters; $column = intdiv($column, 26) - 1; } while ($column >= 0);
        return $letters . $row;
    }

    private function number(float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function styles(): string
    {
        $font = '<font><sz val="10"/><name val="Noto Sans Khmer"/><family val="2"/></font>';
        $fonts = '<fonts count="5">' . $font
            . '<font><b/><sz val="19"/><color rgb="FFFFFFFF"/><name val="Noto Sans Khmer"/><family val="2"/></font>'
            . '<font><sz val="10"/><color rgb="FFFFFFFF"/><name val="Noto Sans Khmer"/><family val="2"/></font>'
            . '<font><b/><sz val="13"/><color rgb="FFFFFFFF"/><name val="Noto Sans Khmer"/><family val="2"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Noto Sans Khmer"/><family val="2"/></font>'
            . '<font><sz val="9"/><color rgb="FF64766C"/><i/><name val="Noto Sans Khmer"/><family val="2"/></font>'
            . '</fonts>';
        $fills = '<fills count="9"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF075B35"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF103D2B"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE7F2EB"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF4F7F4"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF5EC"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF3D6"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDEFEF"/></patternFill></fill></fills>';
        $borders = '<borders count="2"><border/><border><left style="thin"><color rgb="FFD9E5DD"/></left><right style="thin"><color rgb="FFD9E5DD"/></right><top style="thin"><color rgb="FFD9E5DD"/></top><bottom style="thin"><color rgb="FFD9E5DD"/></bottom></border></borders>';
        $alignment = '<alignment vertical="center"/>';
        $wrap = '<alignment vertical="center" wrapText="1"/>';
        $xfs = '<cellXfs count="14">'
            . '<xf fontId="0" fillId="0" borderId="0" applyAlignment="1">' . $alignment . '</xf>'
            . '<xf fontId="1" fillId="2" borderId="0" applyAlignment="1">' . $alignment . '</xf>'
            . '<xf fontId="2" fillId="3" borderId="0" applyAlignment="1">' . $alignment . '</xf>'
            . '<xf fontId="3" fillId="2" borderId="0" applyAlignment="1">' . $alignment . '</xf>'
            . '<xf fontId="4" fillId="2" borderId="1" applyAlignment="1">' . $wrap . '</xf>'
            . '<xf fontId="0" fillId="0" borderId="1" applyAlignment="1">' . $wrap . '</xf>'
            . '<xf fontId="5" fillId="0" borderId="0" applyAlignment="1">' . $wrap . '</xf>'
            . '<xf fontId="4" fillId="2" borderId="1" applyAlignment="1">' . $wrap . '</xf>'
            . '<xf fontId="0" fillId="8" borderId="1" applyAlignment="1">' . $wrap . '</xf>'
            . '<xf fontId="0" fillId="0" borderId="1" numFmtId="4" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf fontId="0" fillId="4" borderId="1" applyAlignment="1">' . $wrap . '</xf>'
            . '<xf fontId="0" fillId="6" borderId="1" applyAlignment="1">' . $wrap . '</xf>'
            . '<xf fontId="0" fillId="7" borderId="1" applyAlignment="1">' . $wrap . '</xf>'
            . '<xf fontId="0" fillId="8" borderId="1" applyAlignment="1">' . $wrap . '</xf>'
            . '</cellXfs>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00;[Red]-#,##0.00;0.00"/></numFmts>' . $fonts . $fills . $borders . $xfs . '</styleSheet>';
    }

    private function contentTypes(int $count): string
    {
        $items = '';
        for ($i = 1; $i <= $count; $i++) $items .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $items . '</Types>';
    }

    private function rootRelationships(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'; }

    private function workbook(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>';
        foreach ($sheets as $i => $sheet) {
            $name = ['Overview', 'Profit & Loss', 'Cash Flow'][$i] ?? 'Statement';
            $xml .= '<sheet name="' . $this->escape($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return $xml . '</sheets></workbook>';
    }

    private function workbookRelationships(int $count): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        for ($i = 1; $i <= $count; $i++) $xml .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        return $xml . '</Relationships>';
    }

    private function escape(string $value): string
    {
        $value = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', '', $value);
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}

