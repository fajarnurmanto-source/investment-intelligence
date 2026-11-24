<?php

namespace App\Services;

/**
 * Local, metadata-only provenance of portfolio and strategy calculations.
 * Unknown/missing/legacy source identity never becomes an approved lineage.
 */
final class SignalAnalyticsLineageService
{
    public function portfolio(int $userId, array $documents, array $weights, array $result): array
    {
        $result['lineage_node_id'] = null;
        $result['lineage_status'] = 'PENDING_SOURCE_LINEAGE';
        $parents = $this->parents($userId, $documents);
        if ($parents === null || count($parents) < 2) {
            return $result;
        }
        $input = ['weights' => $weights, 'output' => $this->portfolioProjection($result)];
        return $this->register($userId, $parents, 'portfolio_research', 'ANALYZE_PORTFOLIO_V1', $input, $result);
    }

    public function strategy(int $userId, array $document, array $settings, array $result): array
    {
        $result['lineage_node_id'] = null;
        $result['lineage_status'] = 'PENDING_SOURCE_LINEAGE';
        $parents = $this->parents($userId, [$document]);
        if ($parents === null) {
            return $result;
        }
        $input = ['settings' => $settings, 'output' => $this->strategyProjection($result)];
        return $this->register($userId, $parents, 'strategy_replay', 'REPLAY_STRATEGY_V1', $input, $result);
    }

    private function parents(int $userId, array $documents): ?array
    {
        $registry = app(LocalLineageRegistry::class);
        $parents = [];
        foreach ($documents as $document) {
            if (! is_array($document)
                || app(ByokSnapshotLineage::class)->isRestricted($document)
                || ($document['lineage_source_status'] ?? '') !== 'REGISTERED'
                || empty($document['lineage_source_node_id'])) {
                return null;
            }
            $id = (string) $document['lineage_source_node_id'];
            $node = $registry->owned($userId, $id);
            if ($node === null || $node['kind'] !== 'SOURCE'
                || $node['reference_type'] !== 'signal_ohlcv_document'
                || $node['rights_basis'] === 'RESTRICTED_BYOK'
                || ! $node['can_persist_payload']
                || ! $registry->verifyTrace($userId, $id)) {
                return null;
            }
            $parents[] = $id;
        }
        if (count(array_unique($parents)) !== count($parents) || count($parents) > 16) {
            return null;
        }
        sort($parents, SORT_STRING);

        return $parents;
    }

    private function register(
        int $userId, array $parents, string $type, string $operation,
        array $inputsAndOutput, array $result
    ): array {
        // Numeric outputs and user-selected settings are hashed, not placed
        // in node metadata, and never transmitted off this device.
        $digest = hash('sha256', json_encode(
            $inputsAndOutput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
        $reference = hash('sha256', json_encode(
            [$operation, $parents, $digest], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
        $node = app(LocalLineageRegistry::class)->registerTransformation(
            $userId, $type, $reference, $operation, $digest, $parents
        );
        $result['lineage_node_id'] = $node['id'];
        $result['lineage_status'] = 'REGISTERED_RESEARCH_ONLY';
        $result['lineage_effective_export'] = false;

        return $result;
    }

    private function portfolioProjection(array $result): array
    {
        return array_intersect_key($result, array_fill_keys([
            'tickers', 'start_date', 'end_date', 'common_sessions',
            'portfolio_return_pct', 'equal_weight_return_pct', 'relative_excess_pp',
            'worst_drawdown_pct', 'session_volatility_pct',
            'historical_var_95_pct', 'historical_cvar_95_pct',
            'pairwise_correlations', 'quality', 'synthetic_state',
        ], true));
    }

    private function strategyProjection(array $result): array
    {
        return array_intersect_key($result, array_fill_keys([
            'ticker', 'period_start', 'period_end', 'observations',
            'strategy_ending', 'buy_hold_ending', 'strategy_return_pct',
            'buy_hold_return_pct', 'excess_return_pp', 'max_drawdown_pct',
            'trade_orders', 'closed_trades', 'win_rate_pct',
            'open_position_at_end',
        ], true));
    }
}
