<?php

namespace App\Services;

/**
 * Metadata-only lineage of numeric analytics. No input/output figures, vendor
 * response, API key or CSV contents are persisted into the lineage registry.
 *
 * The digest certifies which calculated projection was observed, NOT source
 * truth, legal entitlement, or tamper-proof replay.
 */
final class DerivedAnalyticsLineageService
{
    public function valuation(
        int $userId,
        string $documentId,
        array $record,
        ?array $documentParent,
        ?array $priceParent,
        bool $externalPriceExpected,
        bool $priceConflict = false,
        ?array $shareBasisParent = null
    ): array {
        $record['lineage_valuation_node_id'] = null;
        $record['lineage_valuation_status'] = 'PENDING_INPUT_REVIEW';
        if (app(ByokSnapshotLineage::class)->isRestricted($record)
            || ($documentParent['rights_basis'] ?? null) === 'RESTRICTED_BYOK'
            || ($priceParent['rights_basis'] ?? null) === 'RESTRICTED_BYOK'
            || ($shareBasisParent['rights_basis'] ?? null) === 'RESTRICTED_BYOK') {
            $record['lineage_valuation_status'] = 'DENIED_RESTRICTED_ANCESTRY';

            return $record;
        }
        if ($documentParent === null || ($externalPriceExpected && $priceParent === null)
            || ($record['share_basis_status'] ?? null) === 'ALIGNED_USER_ATTESTED'
                && $shareBasisParent === null
            || $priceConflict) {
            return $record;
        }
        $parentIds = [$documentParent['id']];
        if ($priceParent !== null) {
            $parentIds[] = $priceParent['id'];
        }
        if ($shareBasisParent !== null && ($record['share_basis_status'] ?? '') === 'ALIGNED_USER_ATTESTED') {
            $parentIds[] = $shareBasisParent['id'];
        }
        $registry = app(LocalLineageRegistry::class);
        foreach ($parentIds as $parentId) {
            if (! $registry->verifyTrace($userId, $parentId)) {
                $record['lineage_valuation_status'] = 'FAILED_PARENT_INTEGRITY';

                return $record;
            }
        }
        $fields = [
            'ticker', 'latest_year', 'report_period', 'valuation_status',
            'net_margin_pct', 'revenue_growth_pct', 'earnings_growth_pct',
            'roe_end_pct', 'debt_equity_ratio', 'eps', 'pe_ratio', 'pbv_ratio',
            'ev_ebitda_ratio', 'valuation_market_price', 'valuation_shares_outstanding',
            'market_price_as_of', 'operating_cash_flow', 'share_basis_status',
            'share_basis_factor', 'income_positive', 'cash_flow_positive',
        ];
        $projection = array_intersect_key($record, array_fill_keys($fields, true));
        $hash = hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        // Version by input parent identities AND output hash. New prices and
        // reconciliation changes create new immutable nodes; old claims stay.
        $reference = hash('sha256', json_encode([
            'issuer_valuation.v1', $documentId, $parentIds, $hash,
        ], JSON_THROW_ON_ERROR));
        $node = app(LocalLineageRegistry::class)->registerTransformation(
            $userId, 'issuer_valuation', $reference,
            'DERIVE_ISSUER_VALUATION_V1', $hash, $parentIds
        );

        $record['lineage_valuation_node_id'] = $node['id'];
        $record['lineage_valuation_status'] = 'REGISTERED_RESEARCH_ONLY';
        $record['lineage_effective_export'] = false;

        return $record;
    }

    public function screener(int $userId, array $rows, array $filters): array
    {
        $registry = app(LocalLineageRegistry::class);
        foreach ($rows as &$row) {
            $row['lineage_screener_node_id'] = null;
            $row['lineage_screener_status'] = 'PENDING_VALUATION_LINEAGE';
            if (($row['byok_lineage'] ?? '') === 'RESTRICTED_BYOK_SOURCE_DETECTED'
                || ($row['lineage_valuation_status'] ?? '') !== 'REGISTERED_RESEARCH_ONLY'
                || empty($row['lineage_valuation_node_id'])) {
                continue;
            }
            $parent = $registry->owned($userId, (string) $row['lineage_valuation_node_id']);
            if ($parent === null
                || $parent['operation'] !== 'DERIVE_ISSUER_VALUATION_V1'
                || $parent['kind'] !== 'TRANSFORMATION'
                || $parent['rights_basis'] === 'RESTRICTED_BYOK'
                || ! $parent['can_persist_payload']
                || ! $registry->verifyTrace($userId, (string) $row['lineage_valuation_node_id'])) {
                continue;
            }

            // Scoring depends on financial and quality indicators, all
            // represented by the parent valuation projection. Filter inputs
            // are kept in the hash only, never in registry source_metadata.
            $projection = [
                'ticker' => $row['ticker'] ?? null,
                'quality_score' => $row['quality_score'] ?? null,
                'screen_reasons' => $row['screen_reasons'] ?? [],
                'filters' => $filters,
            ];
            $hash = hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $reference = hash('sha256', json_encode([
                'fundamental_screener.v1', $row['lineage_valuation_node_id'], $hash,
            ], JSON_THROW_ON_ERROR));
            $node = $registry->registerTransformation(
                $userId, 'issuer_screener', $reference,
                'SCORE_FUNDAMENTAL_SCREEN_V1', $hash,
                [$row['lineage_valuation_node_id']]
            );
            $row['lineage_screener_node_id'] = $node['id'];
            $row['lineage_screener_status'] = 'REGISTERED_RESEARCH_ONLY';
        }
        unset($row);

        return $rows;
    }
}
