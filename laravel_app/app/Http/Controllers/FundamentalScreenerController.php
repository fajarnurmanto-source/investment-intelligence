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
        [$filters, $rows, $sectors, $periods] = $this->result($request, $snapshots, $screener);

        return response()->view('ii.screener', compact('filters', 'rows', 'sectors', 'periods'))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, FundamentalSnapshotService $snapshots, FundamentalScreenerService $screener): Response
    {
        [, $rows] = $this->result($request, $snapshots, $screener);
        $writer = Writer::createFromString();
        $writer->insertOne(['ticker', 'issuer_name', 'sector', 'period', 'screen_score', 'completeness_score', 'source_authenticity', 'numeric_reconciliation', 'licensing_assessment', 'provenance_confidence', 'conflict_status', 'pe_ratio', 'pbv_ratio', 'ev_ebitda_ratio', 'market_price', 'market_price_as_of', 'market_price_source', 'market_price_rights', 'valuation_status', 'share_basis_status', 'share_basis_factor', 'share_basis_source', 'net_margin_pct', 'revenue_growth_pct', 'earnings_growth_pct', 'roe_pct', 'debt_equity_ratio', 'operating_cash_flow', 'eps', 'source_name', 'license_status', 'evidence_status', 'decision', 'trading_authority']);
        foreach ($rows as $row) {
            $writer->insertOne(array_map($this->csvCell(...), [
                $row['ticker'], $row['issuer_name'], $row['sector'], $row['latest_year'], $row['quality_score'], $row['data_quality_score'], $row['source_authenticity_status'], $row['numeric_reconciliation_status'], $row['licensing_assessment'], $row['provenance_confidence'], $row['conflict_status'], $row['pe_ratio'], $row['pbv_ratio'], $row['ev_ebitda_ratio'], $row['valuation_market_price'], $row['market_price_as_of'], $row['market_price_source'], $row['market_price_rights'], $row['valuation_status'],
                $row['share_basis_status'] ?? null, $row['share_basis_factor'] ?? null, $row['share_basis_source'] ?? null,
                $row['net_margin_pct'], $row['revenue_growth_pct'], $row['earnings_growth_pct'], $row['roe_end_pct'],
                $row['debt_equity_ratio'], $row['operating_cash_flow'], $row['eps'],
                $row['source_name'], $row['license_status'], $row['gate'], $row['decision'], $row['trading_authority'],
            ]));
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
            'sector' => ['nullable', 'string', 'max:80'],
            'period' => ['nullable', 'integer', 'between:1900,2100'],
            'sort' => ['nullable', 'in:quality_score,data_quality_score,pe_ratio,pbv_ratio,ev_ebitda_ratio,net_margin_pct,revenue_growth_pct,earnings_growth_pct,roe_end_pct,debt_equity_ratio,eps'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);
        $filters = [
            'min_margin' => isset($data['min_margin']) ? (float) $data['min_margin'] : null,
            'min_growth' => isset($data['min_growth']) ? (float) $data['min_growth'] : null,
            'min_roe' => isset($data['min_roe']) ? (float) $data['min_roe'] : null,
            'cash_positive' => (bool) ($data['cash_positive'] ?? false),
            'sector' => isset($data['sector']) && $data['sector'] !== '' ? (string) $data['sector'] : null,
            'period' => isset($data['period']) ? (int) $data['period'] : null,
            'sort' => (string) ($data['sort'] ?? 'quality_score'),
            'sort_direction' => (string) ($data['sort_direction'] ?? 'desc'),
        ];
        $records = $snapshots->latestByTicker((int) auth()->id());
        $sectors = collect($records)->pluck('sector')->filter()->unique()->sort()->values()->all();
        $periods = collect($records)->pluck('latest_year')->filter()->unique()->sortDesc()->values()->all();

        return [$filters, $screener->screen($records, $filters), $sectors, $periods];
    }

    private function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            if ((float) $value === 0.0) {
                return '0';
            }

            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        }
        $text = (string) $value;

        return preg_match('/^\s*[=+\-@]/u', $text) ? "'".$text : $text;
    }
}
