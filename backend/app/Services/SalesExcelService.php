<?php

namespace App\Services;

use App\Models\Sale;
use ZipArchive;

class SalesExcelService
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function create(string $workspace, string $from, string $to, $sales): string
    {
        $rows = [['KotTraLuy Sales Management'], ['Workspace', $workspace], ['Selected period', "$from to $to"], [], ['Sale date and time', 'Product name', 'SKU', 'Quantity', 'Unit price', 'Total amount', 'Currency', 'Payment method', 'Payment status', 'Staff member']];
        foreach ($sales as $sale) foreach ($sale->items as $item) $rows[] = [optional($sale->sold_at)->format('Y-m-d H:i:s'), $item->product?->name ?? '', $item->product?->sku ?? '', (int) $item->quantity, (float) $item->unit_price, (float) $sale->total_amount, $sale->currency, $sale->payment_method ?: 'cash', $sale->payment_status ?: 'unpaid', $sale->user?->name ?? $sale->user?->email ?? ''];
        $totals = $sales->groupBy('currency')->map(fn ($items) => $items->sum(fn ($sale) => (float) $sale->total_amount));
        $rows[] = []; $rows[] = ['Summary of total sales by currency']; foreach ($totals as $currency => $total) $rows[] = [$currency, (float) $total];
        $path = tempnam(sys_get_temp_dir(), 'kottraluy-sales-'); $zip = new ZipArchive(); if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Unable to create Excel workbook.');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sales" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $row) { $xml .= '<row r="'.($r+1).'">'; foreach ($row as $c => $value) { $ref = chr(65+$c).($r+1); $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string)$value, ENT_XML1).'</t></is></c>'; } $xml .= '</row>'; }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>'); $zip->close(); return $path;
    }
}
