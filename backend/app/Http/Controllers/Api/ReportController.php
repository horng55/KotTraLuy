<?php

namespace App\Http\Controllers\Api;

use App\Services\ReportService;
use App\Services\FinancialExcelService;
use Illuminate\Http\Request;

class ReportController
{
    public function financial(Request $request, ReportService $reportService)
    {
        abort_unless($request->user()->business_id && $request->user()->status === 'active', 403);
        $data = $request->validate(['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from','currency'=>'sometimes|required|in:USD,KHR']);
        return response()->json($reportService->financialReport($request->user()->business, $data['from'], $data['to'], $data['currency'] ?? null));
    }

    public function financialExcel(Request $request, ReportService $reportService, FinancialExcelService $excel)
    {
        abort_unless($request->user()->business_id && $request->user()->status === 'active', 403);
        $data = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'currency' => 'sometimes|required|in:USD,KHR',
            'language' => 'sometimes|in:en,km',
        ]);
        $report = $reportService->financialReport($request->user()->business, $data['from'], $data['to'], $data['currency'] ?? null);
        $path = $excel->create($report, $data['language'] ?? 'en');
        $filename = 'KotTraLuy_Financial_Report_' . $data['from'] . '_to_' . $data['to'] . '.xlsx';
        return response()->download($path, $filename, ['Content-Type' => $excel->mime()])->deleteFileAfterSend(true);
    }
    public function daily(Request $request, ReportService $reportService)
    {
        $date = $request->query('date', now()->toDateString());

        return response()->json($reportService->dailySummary($request->user()->business, $date));
    }

    public function monthly(Request $request, ReportService $reportService)
    {
        $month = $request->query('month', now()->format('Y-m'));

        return response()->json($reportService->monthlySummary($request->user()->business, $month));
    }

    public function range(Request $request, ReportService $reportService)
    {
        $data = $request->validate(['from'=>'required|date','to'=>'required|date|after_or_equal:from']);
        return response()->json($reportService->rangeSummary($request->user()->business, $data['from'], $data['to']));
    }

    public function csv(Request $request, ReportService $reportService)
    {
        $data = $request->validate(['from'=>'required|date','to'=>'required|date|after_or_equal:from']);
        $summary = $reportService->rangeSummary($request->user()->business, $data['from'], $data['to']);
        return response()->streamDownload(function () use ($summary) { $out = fopen('php://output','w'); fputcsv($out,['Business Record Summary — Self-reported']); foreach ($summary as $key=>$value) fputcsv($out,[$key,$value]); fclose($out); }, 'kottraluy-business-summary.csv', ['Content-Type'=>'text/csv']);
    }
}
