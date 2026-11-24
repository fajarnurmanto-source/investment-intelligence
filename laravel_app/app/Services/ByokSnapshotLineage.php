<?php

namespace App\Services;

/**
 * Source-aware lineage for the issuer snapshot boundary.
 *
 * Only known restricted identities are classified. Unknown source rights are
 * never promoted to export permission; metadata labels are not legal proof.
 */
final class ByokSnapshotLineage
{
    public function isRestricted(array $record): bool
    {
        foreach (['source_name', 'market_price_source', 'publisher'] as $field) {
            $name = strtolower(trim((string) ($record[$field] ?? '')));
            if (preg_match('/\bmarketdata\s*(?:\.\s*app|byok)\b/i', $name)) {
                return true;
            }
        }
        foreach (['source_url', 'market_price_source_url'] as $field) {
            $url = trim((string) ($record[$field] ?? ''));
            if ($url === '') {
                continue;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === 'marketdata.app' || str_ends_with($host, '.marketdata.app')) {
                return true;
            }
        }

        return false;
    }

    public function apply(array $record): array
    {
        if (! $this->isRestricted($record)) {
            // This is not an affirmative export entitlement. Source-specific
            // rights still govern the existing CSV export gates.
            $record['byok_lineage'] = 'NO_RESTRICTED_BYOK_SOURCE_IDENTIFIED';
            $record['byok_export_clearance'] = 'NOT_ASSESSED';

            return $record;
        }

        // A legacy record carrying known restricted source data must not
        // silently produce transferable ratios, valuations or screen scores.
        $numericFields = [
            'net_margin_pct', 'revenue_growth_pct', 'earnings_growth_pct',
            'roe_end_pct', 'debt_equity_ratio', 'eps', 'pe_ratio', 'pbv_ratio',
            'ev_ebitda_ratio', 'valuation_market_price', 'valuation_shares_outstanding',
            'operating_cash_flow', 'share_basis_factor',
        ];
        foreach ($numericFields as $key) {
            if (array_key_exists($key, $record)) {
                $record[$key] = null;
            }
        }
        if (is_array($record['years'] ?? null)) {
            $record['years'] = array_map(static function (array $year): array {
                foreach ($year as $key => $value) {
                    if (! in_array($key, ['year', 'period_end'], true)) {
                        $year[$key] = null;
                    }
                }

                return $year;
            }, $record['years']);
        }
        $record['byok_lineage'] = 'RESTRICTED_BYOK_SOURCE_DETECTED';
        $record['byok_export_clearance'] = 'DENIED';
        $record['valuation_status'] = 'N/A_BYOK_RESTRICTED_SOURCE';
        $record['gate'] = 'NOT_ADMITTED';
        $record['decision'] = 'HOLD';
        $record['trading_authority'] = 'NONE';
        $record['trading_authorized'] = false;

        return $record;
    }
}
