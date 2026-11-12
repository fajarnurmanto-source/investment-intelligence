<?php

namespace App\Services;

use App\Models\IssuerDocument;
use Illuminate\Support\Collection;

/**
 * Owner-scoped source activation queue derived from evidence already imported.
 * No network fetch, vendor credential, or implied IDX licensing.
 */
class IssuerCoverageService
{
    public function report(int $userId, array $snapshots, Collection $priceReviews): array
    {
        $docs = IssuerDocument::query()->with(['source', 'periods'])
            ->where('user_id', $userId)->orderByDesc('imported_at')->get()
            ->groupBy('issuer_code');

        $prices = $priceReviews->keyBy('ticker');
        $tickers = array_unique(array_merge(array_keys($snapshots), $docs->keys()->all(), $prices->keys()->all()));
        sort($tickers, SORT_STRING);

        $rows = [];
        foreach ($tickers as $ticker) {
            $versions = $docs->get($ticker, collect());
            $candidate = $versions->sortByDesc(function (IssuerDocument $doc): string {
                $end = $doc->periods->map(fn ($p): string => $p->period_end->toDateString())->max();

                return ($end ?? '0000-00-00').'|'.($doc->imported_at?->format('Y-m-d H:i:s.u') ?? '');
            })->first();
            $snapshot = $snapshots[$ticker] ?? null;
            $price = $prices->get($ticker);
            $periodEnds = $versions->flatMap(fn (IssuerDocument $doc) =>
                $doc->periods->map(fn ($p): string => $p->period_end->toDateString())
            )->unique()->values();
            $status = $this->status($candidate, $snapshot, $price, $periodEnds->count());

            $rows[] = [
                'ticker' => $ticker,
                'issuer_name' => $candidate?->issuer_name ?? ($snapshot['issuer_name'] ?? $ticker),
                'documents' => $versions->count(),
                'periods' => $periodEnds->count(),
                'latest_period' => $periodEnds->max(),
                'source_name' => $candidate?->source?->name ?? ($snapshot['source_name'] ?? null),
                'source_url' => $candidate?->source?->source_url ?? ($snapshot['source_url'] ?? null),
                'source_claim' => $candidate?->source_authenticity_status ?? ($snapshot['source_authenticity_status'] ?? 'UNVERIFIED'),
                'numeric_claim' => $candidate?->numeric_reconciliation_status ?? ($snapshot['numeric_reconciliation_status'] ?? 'UNRECONCILED'),
                'usage_rights' => $candidate?->source?->usage_rights ?? ($snapshot['usage_rights'] ?? 'unknown'),
                'price_date' => $price['observed_on'] ?? ($snapshot['market_price_as_of'] ?? null),
                'price_status' => $price['status'] ?? ($snapshot['valuation_status'] ?? 'N/A_MISSING_MARKET_PRICE'),
                'valuation_status' => $snapshot['valuation_status'] ?? 'N/A_MISSING_FUNDAMENTALS',
                'next_action' => $status,
                'gate' => 'NOT_ADMITTED',
                'decision' => 'HOLD',
                'trading_authority' => 'NONE',
            ];
        }

        return [
            'rows' => $rows,
            'issuer_count' => count($rows),
            'has_fundamentals' => count(array_filter($rows, fn (array $r): bool => $r['periods'] > 0)),
            'has_market_prices' => count(array_filter($rows, fn (array $r): bool => $r['price_date'] !== null)),
            'review_required' => count(array_filter($rows, fn (array $r): bool => $r['next_action'] !== 'RESEARCH_READY_HOLD')),
        ];
    }

    private function status(?IssuerDocument $doc, ?array $snapshot, ?array $price, int $periodCount): string
    {
        if ($doc === null && $snapshot === null) {
            return 'DOCUMENT_REQUIRED';
        }
        if ($periodCount === 0 && $snapshot === null) {
            return 'NUMERIC_EXTRACTION_REQUIRED';
        }
        $rights = $doc?->source?->usage_rights ?? ($snapshot['usage_rights'] ?? 'unknown');
        $license = $doc?->source?->license_status ?? ($snapshot['license_status'] ?? 'unknown');
        if ($rights === 'unknown' || $license === 'unknown') {
            return 'RIGHTS_REVIEW_REQUIRED';
        }
        if ($snapshot === null) {
            return 'NORMALIZATION_REVIEW_REQUIRED';
        }
        if (! in_array($snapshot['source_authenticity_status'] ?? '', ['OFFICIAL_PUBLISHER', 'LICENSED_PROVIDER'], true)) {
            return 'SOURCE_ATTESTATION_REQUIRED';
        }
        if (($snapshot['numeric_reconciliation_status'] ?? '') !== 'VERIFIED_AGAINST_SOURCE') {
            return 'NUMERIC_RECONCILIATION_REQUIRED';
        }
        if ($price === null && empty($snapshot['market_price_as_of'])) {
            return 'LICENSED_PRICE_REQUIRED';
        }
        if ($price !== null && $price['conflict']) {
            return 'PRICE_CONFLICT_REVIEW_REQUIRED';
        }
        if ($price !== null && $price['status'] === 'N/A_FUTURE_MARKET_PRICE_DATE') {
            return 'PRICE_DATE_REVIEW_REQUIRED';
        }
        if ($price !== null && $price['status'] === 'N/A_SPLIT_ADJUSTMENT_UNALIGNED') {
            return 'PRICE_ADJUSTMENT_REVIEW_REQUIRED';
        }
        if (str_starts_with((string) ($snapshot['valuation_status'] ?? ''), 'AVAILABLE_')) {
            return 'RESEARCH_READY_HOLD';
        }

        return 'VALUATION_REVIEW_REQUIRED';
    }
}
