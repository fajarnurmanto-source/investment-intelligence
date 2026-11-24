<?php

namespace App\Services;

use App\Models\IssuerDocument;
use App\Models\IssuerMarketPrice;
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
            // The same latest-date quality assessment feeds valuation and the
            // V7 reviewer interface; never resolve conflicting vendor data by import order.
            $quality = Schema::hasTable('ii_issuer_market_prices')
                ? app(MarketPriceQualityService::class)->latestByTicker($userId)
                : collect();
            $prices = $quality->map(fn ($review) => $review['price']);
            $priceConflicts = $quality->map(fn ($review) => $review['conflict']);
            $documents = IssuerDocument::query()->with(['source', 'periods' => fn ($q) => $q->orderBy('fiscal_year')->orderBy('period_end')])
                ->where('user_id', $userId)->get();
            $versions = $documents->groupBy('issuer_code')->map->count();
            $latestDocuments = $documents->groupBy('issuer_code')->map(fn ($group) => $group->sortByDesc(function (IssuerDocument $document): string {
                $latestPeriod = $document->periods->sortByDesc(fn ($period) => $period->period_end->format('Y-m-d'))->first();

                return ($latestPeriod?->period_end?->format('Y-m-d') ?? '0000-00-00').'|'.($document->imported_at?->format('Y-m-d H:i:s.u') ?? '');
            })->first());
            foreach ($latestDocuments as $document) {
                if ($document->periods->isEmpty()) {
                    continue;
                }
                $out[$document->issuer_code] = $this->summarizeDocument($document, (int) $versions[$document->issuer_code], $prices->get($document->issuer_code), (bool) $priceConflicts->get($document->issuer_code, false));
            }
        }
        // Apply source rights at the shared data boundary, before the same
        // snapshot can feed screener, coverage, valuation or Blade views.
        $lineage = app(ByokSnapshotLineage::class);
        foreach ($out as $ticker => $record) {
            $out[$ticker] = $lineage->apply($record);
        }
        ksort($out);

        return $out;
    }

    private function summarizeDocument(IssuerDocument $document, int $versions, ?IssuerMarketPrice $marketObservation = null, bool $priceConflict = false): array
    {
        $years = $document->periods->map(fn ($row) => collect($row->getAttributes())->map(fn ($v, $k) => in_array($k, ['id', 'fiscal_year'], true) ? $v : (is_numeric($v) ? (float) $v : $v))->all())->values()->all();
        $last = $years[array_key_last($years)];
        $prev = count($years) > 1 ? $years[count($years) - 2] : null;
        // Eloquent's date cast serializes raw attributes with a midnight time;
        // normalize to the canonical YYYY-MM-DD key used by share-basis reviews.
        $periodEnd = $document->periods->last()->period_end->toDateString();
        $ratio = fn (?float $n, ?float $d) => $n !== null && $d !== null && $d != 0.0 ? round(100 * $n / $d, 2) : null;
        $growth = fn (string $key) => $prev && $last[$key] !== null && $prev[$key] !== null && (float) $prev[$key] != 0.0
            ? round(100 * ((float) $last[$key] - (float) $prev[$key]) / abs((float) $prev[$key]), 2) : null;

        $netIncome = $this->number($last['net_income_attributable'] ?? null);
        $totalEquity = $this->number($last['total_equity'] ?? null);
        $equity = $this->number($last['equity_attributable'] ?? null);
        $shares = $this->number($last['shares_outstanding'] ?? null);
        $embeddedPrice = $this->number($last['market_price'] ?? null);
        $debt = $this->number($last['total_debt'] ?? null);
        $cash = $this->number($last['cash_and_equivalents'] ?? null);
        $ebitda = $this->number($last['ebitda'] ?? null);
        $marketPriceAsOf = $marketObservation?->observed_on?->toDateString() ?? ($last['market_price_as_of'] ?? null);
        $price = $marketObservation ? $this->number($marketObservation->price) : $embeddedPrice;
        $priceRights = $marketObservation?->usage_rights ?? ($embeddedPrice !== null ? $document->source->usage_rights : null);
        $rightsAligned = in_array($priceRights, ['internal_research', 'licensed_internal', 'redistribution_permitted'], true);
        $adjustmentAligned = $marketObservation === null || in_array($marketObservation->adjustment_status, ['UNADJUSTED', 'UNADJUSTED_CLOSE'], true);
        // Any separately sourced raw price after fiscal close needs a sourced
        // share-basis review even when the vendor labels it "unadjusted".
        $basis = $marketObservation !== null && $marketPriceAsOf !== null
            ? app(IssuerShareBasisReconciliationService::class)->resolve(
                (int) $document->user_id, $document->issuer_code, $periodEnd,
                $marketPriceAsOf, $shares
            )
            : null;
        $basisAligned = $marketObservation === null
            || in_array($basis['status'] ?? '', ['ALIGNED_SAME_DAY', 'ALIGNED_USER_ATTESTED'], true);
        $valuationShares = $basisAligned ? ($basis['shares'] ?? $shares) : $shares;
        $priceAligned = ! $priceConflict && $price !== null && $marketPriceAsOf !== null
            && $marketPriceAsOf <= now()->toDateString()
            && ($marketObservation === null || ($marketObservation->currency === $document->currency && $marketObservation->unit === 'per_share'))
            && $marketPriceAsOf >= $periodEnd && $rightsAligned && $adjustmentAligned && $basisAligned;
        $marketCap = $priceAligned && $valuationShares !== null ? $price * $valuationShares : null;
        $enterpriseValue = $marketCap !== null && $debt !== null && $cash !== null ? $marketCap + $debt - $cash : null;
        $eps = $netIncome !== null && $valuationShares !== null && $valuationShares > 0 ? $netIncome / $valuationShares : null;
        $bookValuePerShare = $equity !== null && $valuationShares !== null && $valuationShares > 0 ? $equity / $valuationShares : null;

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
            'ticker' => $document->issuer_code, 'issuer_name' => $document->issuer_name, 'sector' => $document->sector,
            'kind' => 'user_supplied', 'fiscal_period_type' => $document->fiscal_period_type,
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
            'conflict_status' => $document->conflict_status, 'conflict_details' => $document->conflict_details ?? [],
            'taxonomy_status' => $document->taxonomy_status, 'taxonomy_namespaces' => $document->taxonomy_namespaces ?? [],
            'net_margin_pct' => $ratio($netIncome, $this->number($last['revenue'] ?? null)),
            'revenue_growth_pct' => $growth('revenue'), 'earnings_growth_pct' => $growth('net_income_attributable'),
            'roe_end_pct' => $equity !== null && $equity > 0 ? $ratio($netIncome, $equity) : null,
            'debt_equity_ratio' => $debt !== null && $totalEquity !== null && $totalEquity > 0 ? round($debt / $totalEquity, 4) : null,
            'eps' => $eps !== null ? round($eps, 4) : null,
            'pe_ratio' => $priceAligned && $price > 0 && $eps !== null && $eps > 0 ? round($price / $eps, 2) : null,
            'pbv_ratio' => $priceAligned && $price > 0 && $bookValuePerShare !== null && $bookValuePerShare > 0 ? round($price / $bookValuePerShare, 2) : null,
            'ev_ebitda_ratio' => $enterpriseValue !== null && $ebitda !== null && $ebitda > 0 ? round($enterpriseValue / $ebitda, 2) : null,
            'market_price_as_of' => $marketPriceAsOf,
            'valuation_market_price' => $price,
            'market_price_source' => $marketObservation?->source_name ?? ($embeddedPrice !== null ? $document->source->name : null),
            'market_price_source_url' => $marketObservation?->source_url ?? ($embeddedPrice !== null ? $document->source->source_url : null),
            'market_price_rights' => $priceRights,
            'share_basis_status' => $basis['status'] ?? 'NOT_APPLICABLE',
            'share_basis_factor' => $basis['factor'] ?? null,
            'share_basis_source' => $basis['source'] ?? null,
            'valuation_shares_outstanding' => $priceAligned ? $valuationShares : null,
            'valuation_status' => $this->valuationStatus($price, $marketPriceAsOf, $marketObservation, $document, $periodEnd, [$eps, $bookValuePerShare, $enterpriseValue, $ebitda], $basis['status'] ?? null, $priceConflict),
            'operating_cash_flow' => $this->number($last['operating_cash_flow'] ?? null),
            'income_positive' => $netIncome !== null && $netIncome > 0,
            'cash_flow_positive' => ($this->number($last['operating_cash_flow'] ?? null) ?? 0) > 0,
            'gate' => 'NOT_ADMITTED', 'decision' => 'HOLD', 'trading_authority' => 'NONE', 'trading_authorized' => false,
        ];
    }

    private function valuationStatus(?float $price, ?string $asOf, ?IssuerMarketPrice $observation, IssuerDocument $document, string $periodEnd, array $components, ?string $basisStatus = null, bool $priceConflict = false): string
    {
        if ($price === null) {
            return 'N/A_MISSING_MARKET_PRICE';
        }
        if ($asOf === null) {
            return 'N/A_MISSING_PRICE_DATE';
        }
        if ($asOf > now()->toDateString()) {
            return 'N/A_FUTURE_MARKET_PRICE_DATE';
        }
        if ($priceConflict) {
            return 'N/A_MARKET_PRICE_CONFLICT';
        }
        if ($observation && $observation->currency !== $document->currency) {
            return 'N/A_PRICE_CURRENCY_MISMATCH';
        }
        if ($observation && $observation->unit !== 'per_share') {
            return 'N/A_PRICE_UNIT_MISMATCH';
        }
        if ($observation && $observation->adjustment_status === 'SPLIT_ADJUSTED') {
            return 'N/A_SPLIT_ADJUSTMENT_UNALIGNED';
        }
        if ($asOf < $periodEnd) {
            return 'N/A_PRICE_PREDATES_REPORT_PERIOD';
        }
        if ($observation && ! in_array($basisStatus, ['ALIGNED_SAME_DAY', 'ALIGNED_USER_ATTESTED'], true)) {
            return $basisStatus ?? 'N/A_SHARE_BASIS_REVIEW_MISSING';
        }
        $rights = $observation?->usage_rights ?? $document->source->usage_rights;
        if (! in_array($rights, ['internal_research', 'licensed_internal', 'redistribution_permitted'], true)) {
            return 'N/A_PRICE_RIGHTS_UNVERIFIED';
        }
        if (count(array_filter($components, fn ($value) => $value !== null)) < 2) {
            return 'N/A_INCOMPLETE_COMPONENTS';
        }

        return ($basisStatus === 'ALIGNED_USER_ATTESTED' ? 'AVAILABLE_USER_RECONCILED_AS_OF_' : 'AVAILABLE_AS_OF_').$asOf;
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

        return ['ticker' => $doc['ticker'], 'issuer_name' => $doc['ticker'], 'sector' => 'Legacy / Unclassified',
            'kind' => $doc['kind'], 'fiscal_period_type' => 'annual', 'currency' => $doc['currency'], 'multiplier' => $m,
            'report_period' => 'annual', 'report_date' => $last['year'].'-12-31', 'consolidated' => false, 'source_name' => $doc['source_name'], 'source_url' => $doc['source_url'] ?? null,
            'license_status' => 'legacy_unspecified', 'usage_rights' => 'legacy_user_attested', 'redistribution_allowed' => false, 'sha256' => null,
            'imported_at' => $doc['imported_at'], 'latest_year' => $last['year'], 'years' => $annual, 'version_count' => 1, 'quality_status' => 'LEGACY', 'data_quality_score' => 50,
            'source_authenticity_status' => 'UNVERIFIED', 'numeric_reconciliation_status' => 'UNRECONCILED',
            'licensing_assessment' => 'UNVERIFIED', 'provenance_confidence' => 'LOW',
            'conflict_status' => 'LEGACY_UNASSESSED', 'conflict_details' => [], 'taxonomy_status' => 'LEGACY_NOT_APPLICABLE', 'taxonomy_namespaces' => [],
            'net_margin_pct' => $pct($last['net_income_attributable'], $last['revenue']), 'revenue_growth_pct' => $pct($last['revenue'] - $prev['revenue'], abs($prev['revenue'])),
            'earnings_growth_pct' => $pct($last['net_income_attributable'] - $prev['net_income_attributable'], abs($prev['net_income_attributable'])),
            'roe_end_pct' => $pct($last['net_income_attributable'], $last['equity_attributable']), 'debt_equity_ratio' => null,
            'eps' => $last['shares_outstanding'] > 0 ? round($last['net_income_attributable'] * $m / $last['shares_outstanding'], 4) : null,
            'pe_ratio' => null, 'pbv_ratio' => null, 'ev_ebitda_ratio' => null, 'valuation_market_price' => null,
            'market_price_as_of' => null, 'market_price_source' => null, 'market_price_rights' => null,
            'valuation_status' => 'N/A_MISSING_MARKET_PRICE', 'operating_cash_flow' => $last['operating_cash_flow'] * $m,
            'income_positive' => $last['net_income_attributable'] > 0, 'cash_flow_positive' => $last['operating_cash_flow'] > 0,
            'gate' => 'NOT_ADMITTED', 'decision' => 'HOLD', 'trading_authority' => 'NONE', 'trading_authorized' => false];
    }
}
