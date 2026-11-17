<?php

namespace App\Http\Controllers\Api;

use App\Services\ReportService;
use App\Services\FinancialExcelService;
use App\Services\SalesExcelService;
use App\Models\Sale;
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

    public function salesExcel(Request $request, SalesExcelService $excel)
    {
        $data = $request->validate(['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from','currency'=>'nullable|in:USD,KHR']);
        $sales = Sale::where('business_id', $request->user()->business_id)->whereBetween('sold_at', [$data['from'].' 00:00:00', $data['to'].' 23:59:59'])->when($data['currency'] ?? null, fn ($q, $currency) => $q->where('currency', $currency))->when(!$request->user()->canDo('sales.read.all'), fn ($query) => $query->where('user_id', $request->user()->id))->with(['items.product','user'])->orderBy('sold_at')->get();
        $path = $excel->create($request->user()->business->name, $data['from'], $data['to'], $sales);
        return response()->download($path, 'KotTraLuy_Sales_'.$data['from'].'_to_'.$data['to'].'.xlsx', ['Content-Type'=>SalesExcelService::MIME])->deleteFileAfterSend(true);
    }
    public function daily(Request $request, ReportService $reportService)
    {
        $request->validate(['date' => 'sometimes|date_format:Y-m-d', 'currency' => 'sometimes|in:USD,KHR']);
        $date = $request->query('date', now()->toDateString());

        return response()->json($reportService->dailySummary($request->user()->business, $date, $request->query('currency'), ($request->user()->canDo('sales.read.all') || $request->user()->canDo('reports.financial')) ? null : $request->user()->id));
    }

    public function monthly(Request $request, ReportService $reportService)
    {
        $request->validate(['month' => 'sometimes|date_format:Y-m', 'currency' => 'sometimes|in:USD,KHR']);
        $month = $request->query('month', now()->format('Y-m'));

        return response()->json($reportService->monthlySummary($request->user()->business, $month, $request->query('currency'), ($request->user()->canDo('sales.read.all') || $request->user()->canDo('reports.financial')) ? null : $request->user()->id));
    }

    public function range(Request $request, ReportService $reportService)
    {
        $data = $request->validate(['from'=>'required|date','to'=>'required|date|after_or_equal:from','currency'=>'sometimes|in:USD,KHR']);
        return response()->json($reportService->rangeSummary($request->user()->business, $data['from'], $data['to'], $data['currency'] ?? null, ($request->user()->canDo('sales.read.all') || $request->user()->canDo('reports.financial')) ? null : $request->user()->id));
    }

    public function csv(Request $request, ReportService $reportService)
    {
        $data = $request->validate(['from'=>'required|date','to'=>'required|date|after_or_equal:from','currency'=>'sometimes|in:USD,KHR']);
        $summary = app(\App\Services\WorkspaceDataVisibility::class)->filter($reportService->rangeSummary($request->user()->business, $data['from'], $data['to'], $data['currency'] ?? null, ($request->user()->canDo('sales.read.all') || $request->user()->canDo('reports.financial')) ? null : $request->user()->id), $request->user());
        return response()->streamDownload(function () use ($summary) { $out = fopen('php://output','w'); fputcsv($out,['Business Record Summary — Self-reported']); foreach ($summary as $key=>$value) fputcsv($out,[$key,$value]); fclose($out); }, 'kottraluy-business-summary.csv', ['Content-Type'=>'text/csv']);
    }
}
