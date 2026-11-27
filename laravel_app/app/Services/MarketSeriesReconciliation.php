<?php

namespace App\Services;

/** Diagnostic comparison only; never repairs, merges or admits market observations. */
final class MarketSeriesReconciliation
{
    public function compare(array $left, array $right): array
    {
        $result = ['status' => 'INCOMPARABLE', 'reason' => null, 'overlap_sessions' => 0,
            'conflict_sessions' => 0, 'conflict_fields' => 0, 'independence' => 'UNPROVEN',
            'admitted' => false, 'trading_authorized' => false];
        $registry = app(MarketSourceRegistry::class);
        $integrity = app(ResearchSeriesIntegrity::class);
        if (!$integrity->valid($left) || !$integrity->valid($right)
            || ($left['kind'] ?? null) === 'synthetic' || ($right['kind'] ?? null) === 'synthetic'
            || ($left['lineage_source_status'] ?? null) !== 'REGISTERED'
            || ($right['lineage_source_status'] ?? null) !== 'REGISTERED') {
            return array_replace($result, ['reason' => 'INTEGRITY_OR_LINEAGE_REQUIRED']);
        }
        $a = MarketSourceMetadataService::fromDocument($left);
        $b = MarketSourceMetadataService::fromDocument($right);
        $identity = $left['provider_identity'] ?? null;
        if (!is_string($identity) || !preg_match('/^[A-Z0-9.\-]{1,16}@[A-Z0-9]{4}$/D', $identity)
            || $identity !== ($right['provider_identity'] ?? null)
            || ($left['ticker'] ?? null) !== ($right['ticker'] ?? null)) {
            return array_replace($result, ['reason' => 'INSTRUMENT_IDENTITY_MISMATCH']);
        }
        foreach (['currency', 'price_adjustment', 'session_timezone'] as $field) {
            if ($a[$field] === 'UNKNOWN' || $a[$field] !== $b[$field]) {
                return array_replace($result, ['reason' => 'METADATA_UNKNOWN_OR_MISMATCH']);
            }
        }
        $sourceA = $registry->describe($left);
        $sourceB = $registry->describe($right);
        $entries = $registry->entries();
        $originA = $entries[$sourceA]['upstream'] ?? null;
        $originB = $entries[$sourceB]['upstream'] ?? null;
        if ($sourceA !== null && $sourceA === $sourceB) {
            $result['independence'] = 'SAME_SOURCE';
        } elseif ($originA !== null && $originA === $originB) {
            $result['independence'] = 'SAME_UPSTREAM';
        }
        $rowsA = array_column($left['rows'], null, 'date');
        $rowsB = array_column($right['rows'], null, 'date');
        $common = array_intersect_key($rowsA, $rowsB);
        $result['overlap_sessions'] = count($common);
        foreach ($common as $date => $row) {
            $conflicts = 0;
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                // Exact normalized numeric equality; no arbitrary market tolerance.
                if ((float) $row[$field] !== (float) $rowsB[$date][$field]) {
                    $conflicts++;
                }
            }
            $result['conflict_fields'] += $conflicts;
            $result['conflict_sessions'] += $conflicts > 0 ? 1 : 0;
        }
        $result['status'] = $result['conflict_sessions'] > 0 ? 'CONFLICT'
            : ($result['overlap_sessions'] < 60 ? 'INSUFFICIENT_OVERLAP' : 'AGREEMENT_NOT_INDEPENDENTLY_VERIFIED');
        $result['reason'] = $result['status'];
        return $result;
    }
}
