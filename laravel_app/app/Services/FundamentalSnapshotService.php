<?php

namespace App\Services;

use App\Models\IssuerDocument;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Research-only issuer fundamentals. All outputs remain NOT_ADMITTED/HOLD. */
class FundamentalSnapshotService
{
    private function directory(int $userId): string
    {
        return 'ii_fundamental_hub/u'.$userId;
    }

    /** Compatibility path for snapshots imported before Issuer Intelligence V1. */
    public function save(int $userId, array $document): void
    {
        $path = $this->directory($userId).'/'.$document['ticker'].'__'.bin2hex(random_bytes(12)).'.json';
        if (! Storage::disk('local')->put($path, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))) {
            throw new \RuntimeException('Could not store issuer data.');
        }
    }

    public function latestByTicker(int $userId): array
    {
        $out = $this->legacyLatest($userId);
        if (Schema::hasTable('ii_issuer_documents')) {
            $documents = IssuerDocument::query()->with(['source', 'periods' => fn ($q) => $q->orderBy('fiscal_year')->orderBy('period_end')])
                ->where('user_id', $userId)->orderByDesc('imported_at')->get();
            $versions = $documents->groupBy('issuer_code')->map->count();
            foreach ($documents->unique('issuer_code') as $document) {
                if ($document->periods->isEmpty()) {
                    continue;
                }
                $out[$document->issuer_code] = $this->summarizeDocument($document, (int) $versions[$document->issuer_code]);
            }
        }
        ksort($out);

        return $out;
    }

    private function summarizeDocument(IssuerDocument $document, int $versions): array
    {
        $years = $document->periods->map(fn ($row) => collect($row->getAttributes())->map(fn ($v, $k) => in_array($k, ['id', 'fiscal_year'], true) ? $v : (is_numeric($v) ? (float) $v : $v))->all())->values()->all();
        $last = $years[array_key_last($years)];
        $prev = count($years) > 1 ? $years[count($years) - 2] : null;
        $ratio = fn (?float $n, ?float $d) => $n !== null && $d !== null && $d != 0.0 ? round(100 * $n / $d, 2) : null;
        $growth = fn (string $key) => $prev && $last[$key] !== null && $prev[$key] !== null && (float) $prev[$key] != 0.0
            ? round(100 * ((float) $last[$key] - (float) $prev[$key]) / abs((float) $prev[$key]), 2) : null;

        $netIncome = $this->number($last['net_income_attributable'] ?? null);
        $totalEquity = $this->number($last['total_equity'] ?? null);
        $equity = $this->number($last['equity_attributable'] ?? null);
        $shares = $this->number($last['shares_outstanding'] ?? null);
        $price = $this->number($last['market_price'] ?? null);
        $debt = $this->number($last['total_debt'] ?? null);
        $cash = $this->number($last['cash_and_equivalents'] ?? null);
        $ebitda = $this->number($last['ebitda'] ?? null);
        $marketPriceAsOf = $last['market_price_as_of'] ?? null;
        $marketCap = $price !== null && $shares !== null ? $price * $shares : null;
        $enterpriseValue = $marketCap !== null && $debt !== null && $cash !== null ? $marketCap + $debt - $cash : null;
        $eps = $netIncome !== null && $shares > 0 ? $netIncome / $shares : null;
        $bookValuePerShare = $equity !== null && $shares > 0 ? $equity / $shares : null;

        $annual = array_map(function (array $row) use ($ratio): array {
            return [
                'year' => (int) $row['fiscal_year'], 'period_end' => $row['period_end'], 'restated' => (bool) $row['restated'],
                'revenue' => $this->number($row['revenue'] ?? null), 'net_income' => $this->number($row['net_income_attributable'] ?? null),
                'total_assets' => $this->number($row['total_assets'] ?? null), 'total_equity' => $this->number($row['total_equity'] ?? null), 'equity' => $this->number($row['equity_attributable'] ?? null),
                'operating_cash_flow' => $this->number($row['operating_cash_flow'] ?? null),
                'shares_outstanding' => $this->number($row['shares_outstanding'] ?? null), 'total_debt' => $this->number($row['total_debt'] ?? null),
                'cash_and_equivalents' => $this->number($row['cash_and_equivalents'] ?? null), 'ebitda' => $this->number($row['ebitda'] ?? null),
                'market_price' => $this->number($row['market_price'] ?? null), 'market_price_as_of' => $row['market_price_as_of'] ?? null,
                'net_margin_pct' => $ratio($this->number($row['net_income_attributable'] ?? null), $this->number($row['revenue'] ?? null)),
                'roe_end_pct' => $ratio($this->number($row['net_income_attributable'] ?? null), $this->number($row['equity_attributable'] ?? null)),
            ];
        }, $years);

        return [
            'ticker' => $document->issuer_code, 'issuer_name' => $document->issuer_name, 'kind' => 'user_supplied',
            'currency' => $document->currency, 'multiplier' => 1, 'report_period' => $document->report_period,
            'report_date' => $document->report_date->toDateString(), 'consolidated' => $document->consolidated,
            'source_name' => $document->source->name, 'source_url' => $document->source->source_url,
            'license_status' => $document->source->license_status, 'usage_rights' => $document->source->usage_rights,
            'redistribution_allowed' => $document->source->redistribution_allowed,
            'sha256' => $document->sha256, 'imported_at' => $document->imported_at->toIso8601String(),
            'latest_year' => (int) $last['fiscal_year'], 'years' => $annual, 'version_count' => $versions,
            'quality_status' => $document->quality_status, 'data_quality_score' => $document->quality_score,
            'source_authenticity_status' => $document->source_authenticity_status,
            'numeric_reconciliation_status' => $document->numeric_reconciliation_status,
            'licensing_assessment' => $document->licensing_assessment,
            'provenance_confidence' => $document->provenance_confidence,
            'net_margin_pct' => $ratio($netIncome, $this->number($last['revenue'] ?? null)),
            'revenue_growth_pct' => $growth('revenue'), 'earnings_growth_pct' => $growth('net_income_attributable'),
            'roe_end_pct' => $equity !== null && $equity > 0 ? $ratio($netIncome, $equity) : null,
            'debt_equity_ratio' => $debt !== null && $totalEquity !== null && $totalEquity > 0 ? round($debt / $totalEquity, 4) : null,
            'eps' => $eps !== null ? round($eps, 4) : null,
            'pe_ratio' => $price !== null && $price > 0 && $eps !== null && $eps > 0 ? round($price / $eps, 2) : null,
            'pbv_ratio' => $price !== null && $price > 0 && $bookValuePerShare !== null && $bookValuePerShare > 0 ? round($price / $bookValuePerShare, 2) : null,
            'ev_ebitda_ratio' => $enterpriseValue !== null && $ebitda !== null && $ebitda > 0 ? round($enterpriseValue / $ebitda, 2) : null,
            'market_price_as_of' => $marketPriceAsOf,
            'valuation_status' => $price === null ? 'N/A_MISSING_MARKET_PRICE' : ($marketPriceAsOf === null ? 'N/A_MISSING_PRICE_DATE' : 'AVAILABLE_AS_OF_'.$marketPriceAsOf),
            'operating_cash_flow' => $this->number($last['operating_cash_flow'] ?? null),
            'income_positive' => $netIncome !== null && $netIncome > 0,
            'cash_flow_positive' => ($this->number($last['operating_cash_flow'] ?? null) ?? 0) > 0,
            'gate' => 'NOT_ADMITTED', 'decision' => 'HOLD', 'trading_authority' => 'NONE', 'trading_authorized' => false,
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function legacyLatest(int $userId): array
    {
        $disk = Storage::disk('local');
        $latest = [];
        foreach ($disk->files($this->directory($userId)) as $path) {
            if (! str_ends_with($path, '.json')) {
                continue;
            }
            try {
                $doc = json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (! is_array($doc) || ! preg_match('/^[A-Z0-9.\-]{1,16}$/', (string) ($doc['ticker'] ?? ''))
                || ! is_array($doc['years'] ?? null) || count($doc['years']) < 2 || ($doc['schema'] ?? '') !== 'ii.fundamentals.vs7.r2') {
                continue;
            }
            $ticker = $doc['ticker'];
            if (! isset($latest[$ticker]) || strcmp((string) $doc['imported_at'], (string) $latest[$ticker]['imported_at']) > 0) {
                $latest[$ticker] = $doc;
            }
        }

        return array_map(fn ($doc) => $this->summarizeLegacy($doc), $latest);
    }

    private function summarizeLegacy(array $doc): array
    {
        $years = $doc['years'];
        usort($years, fn ($a, $b) => $a['year'] <=> $b['year']);
        $last = $years[count($years) - 1];
        $prev = $years[count($years) - 2];
        $m = (float) $doc['multiplier'];
        $pct = fn ($n, $d) => $d != 0 ? round(100 * $n / $d, 2) : null;
        $annual = array_map(fn ($r) => ['year' => $r['year'], 'period_end' => $r['year'].'-12-31', 'restated' => false,
            'revenue' => $r['revenue'] * $m, 'net_income' => $r['net_income_attributable'] * $m, 'total_assets' => $r['total_assets'] * $m, 'total_equity' => null,
            'equity' => $r['equity_attributable'] * $m, 'operating_cash_flow' => $r['operating_cash_flow'] * $m,
            'shares_outstanding' => $r['shares_outstanding'], 'total_debt' => null, 'cash_and_equivalents' => null, 'ebitda' => null,
            'market_price' => null, 'market_price_as_of' => null,
            'net_margin_pct' => $pct($r['net_income_attributable'], $r['revenue']), 'roe_end_pct' => $pct($r['net_income_attributable'], $r['equity_attributable'])], $years);

        return ['ticker' => $doc['ticker'], 'issuer_name' => $doc['ticker'], 'kind' => $doc['kind'], 'currency' => $doc['currency'], 'multiplier' => $m,
            'report_period' => 'annual', 'report_date' => $last['year'].'-12-31', 'consolidated' => false, 'source_name' => $doc['source_name'], 'source_url' => $doc['source_url'] ?? null,
            'license_status' => 'legacy_unspecified', 'usage_rights' => 'legacy_user_attested', 'redistribution_allowed' => false, 'sha256' => null,
            'imported_at' => $doc['imported_at'], 'latest_year' => $last['year'], 'years' => $annual, 'version_count' => 1, 'quality_status' => 'LEGACY', 'data_quality_score' => 50,
            'source_authenticity_status' => 'UNVERIFIED', 'numeric_reconciliation_status' => 'UNRECONCILED',
            'licensing_assessment' => 'UNVERIFIED', 'provenance_confidence' => 'LOW',
            'net_margin_pct' => $pct($last['net_income_attributable'], $last['revenue']), 'revenue_growth_pct' => $pct($last['revenue'] - $prev['revenue'], abs($prev['revenue'])),
            'earnings_growth_pct' => $pct($last['net_income_attributable'] - $prev['net_income_attributable'], abs($prev['net_income_attributable'])),
            'roe_end_pct' => $pct($last['net_income_attributable'], $last['equity_attributable']), 'debt_equity_ratio' => null,
            'eps' => $last['shares_outstanding'] > 0 ? round($last['net_income_attributable'] * $m / $last['shares_outstanding'], 4) : null,
            'pe_ratio' => null, 'pbv_ratio' => null, 'ev_ebitda_ratio' => null, 'market_price_as_of' => null, 'valuation_status' => 'N/A_MISSING_MARKET_PRICE', 'operating_cash_flow' => $last['operating_cash_flow'] * $m,
            'income_positive' => $last['net_income_attributable'] > 0, 'cash_flow_positive' => $last['operating_cash_flow'] > 0,
            'gate' => 'NOT_ADMITTED', 'decision' => 'HOLD', 'trading_authority' => 'NONE', 'trading_authorized' => false];
    }
}
