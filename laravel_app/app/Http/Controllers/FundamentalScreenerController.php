<?php

namespace App\Http\Controllers;

use App\Services\FundamentalScreenerService;
use App\Services\FundamentalSnapshotService;
use Illuminate\Http\Request;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\Response;

class FundamentalScreenerController extends Controller
{
    public function index(Request $request, FundamentalSnapshotService $snapshots, FundamentalScreenerService $screener)
    {
        [$filters, $rows] = $this->result($request, $snapshots, $screener);
        return response()->view('ii.screener', compact('filters', 'rows'))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, FundamentalSnapshotService $snapshots, FundamentalScreenerService $screener): Response
    {
        [, $rows] = $this->result($request, $snapshots, $screener);
        $writer = Writer::createFromString();
        $writer->insertOne(['ticker', 'quality_score', 'net_margin_pct', 'revenue_growth_pct', 'roe_pct', 'eps', 'cash_flow_positive', 'latest_year', 'source_name', 'evidence_status']);
        foreach ($rows as $row) {
            $writer->insertOne([
                $row['ticker'], $row['quality_score'], $row['net_margin_pct'], $row['revenue_growth_pct'],
                $row['roe_end_pct'], $row['eps'], $row['cash_flow_positive'] ? 'TRUE' : 'FALSE',
                $row['latest_year'], $this->csvSafe((string) $row['source_name']), $row['gate'],
            ]);
        }
        return response($writer->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_FUNDAMENTAL_SCREEN.csv"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function result(Request $request, FundamentalSnapshotService $snapshots, FundamentalScreenerService $screener): array
    {
        $data = $request->validate([
            'min_margin' => ['nullable', 'numeric', 'between:-100,100'],
            'min_growth' => ['nullable', 'numeric', 'between:-100,500'],
            'min_roe' => ['nullable', 'numeric', 'between:-100,500'],
            'cash_positive' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:quality_score,net_margin_pct,revenue_growth_pct,roe_end_pct,eps'],
        ]);
        $filters = [
            'min_margin' => (float) ($data['min_margin'] ?? 0),
            'min_growth' => (float) ($data['min_growth'] ?? 0),
            'min_roe' => (float) ($data['min_roe'] ?? 0),
            'cash_positive' => (bool) ($data['cash_positive'] ?? false),
            'sort' => (string) ($data['sort'] ?? 'quality_score'),
        ];
        $records = $snapshots->latestByTicker((int) auth()->id());
        return [$filters, $screener->screen($records, $filters)];
    }

    private function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
