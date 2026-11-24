<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DerivedAnalyticsLineageService;
use App\Services\FundamentalScreenerService;
use App\Services\LocalLineageRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DerivedAnalyticsLineageTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $name = 'Independent evidence'): array
    {
        return [
            'source_name' => $name,
            'source_url' => 'https://example.test/issuer',
            'license_status' => 'user_provided',
            'usage_rights' => 'internal_research',
        ];
    }

    public function test_valuation_has_two_parents_and_screener_inherits_rights_without_payload_storage(): void
    {
        $owner = User::factory()->create();
        $registry = app(LocalLineageRegistry::class);
        $source = $registry->registerSource(
            $owner->id, 'issuer_document', 'document-A', hash('sha256', 'document-A'), $this->source()
        );
        $normalized = $registry->registerTransformation(
            $owner->id, 'issuer_document', 'document-A',
            'NORMALIZE_ISSUER_PERIODS_V1', hash('sha256', 'periods-A'), [$source['id']]
        );
        $price = $registry->registerSource(
            $owner->id, 'issuer_market_price', 'price-A', hash('sha256', 'price-A'), $this->source()
        );

        $record = [
            'ticker' => 'AAPL', 'latest_year' => 2024, 'report_period' => 'FY2024',
            'source_name' => 'Independent evidence', 'net_margin_pct' => 12.5,
            'revenue_growth_pct' => 7.4, 'roe_end_pct' => 16.2,
            'eps' => 3.21, 'pe_ratio' => 15.4, 'valuation_market_price' => 49.4,
            'valuation_status' => 'AVAILABLE_AS_OF_2025-01-02',
            'income_positive' => true, 'cash_flow_positive' => true,
            'data_quality_score' => 88,
        ];
        $service = app(DerivedAnalyticsLineageService::class);
        $valuation = $service->valuation($owner->id, 'document-A', $record, $normalized, $price, true);
        $this->assertSame('REGISTERED_RESEARCH_ONLY', $valuation['lineage_valuation_status']);
        $this->assertNotNull($valuation['lineage_valuation_node_id']);
        $this->assertFalse($registry->allows($owner->id, $valuation['lineage_valuation_node_id'], 'export'));
        $same = $service->valuation($owner->id, 'document-A', $record, $normalized, $price, true);
        $this->assertSame($valuation['lineage_valuation_node_id'], $same['lineage_valuation_node_id']);

        $scored = app(FundamentalScreenerService::class)->screen([$valuation], []);
        $rows = $service->screener($owner->id, $scored, ['sort' => 'quality_score']);
        $this->assertCount(1, $rows);
        $this->assertSame('REGISTERED_RESEARCH_ONLY', $rows[0]['lineage_screener_status']);
        $this->assertNotNull($rows[0]['lineage_screener_node_id']);
        $trace = $registry->trace($owner->id, $rows[0]['lineage_screener_node_id']);
        $this->assertCount(5, $trace['nodes']); // 2 sources + normalize + valuation + score
        $this->assertCount(4, $trace['edges']);
        $this->assertFalse($registry->allows($owner->id, $rows[0]['lineage_screener_node_id'], 'cloud'));

        $again = $service->screener($owner->id, $scored, ['sort' => 'quality_score']);
        $this->assertSame($rows[0]['lineage_screener_node_id'], $again[0]['lineage_screener_node_id']);
        foreach (DB::table('ii_lineage_nodes')->where('user_id', $owner->id)->get() as $node) {
            $this->assertStringNotContainsString('49.4', $node->source_metadata);
            $this->assertStringNotContainsString('15.4', $node->source_metadata);
        }

        $changed = $record;
        $changed['pe_ratio'] = 18.5;
        $versioned = $service->valuation($owner->id, 'document-A', $changed, $normalized, $price, true);
        $this->assertNotSame($valuation['lineage_valuation_node_id'], $versioned['lineage_valuation_node_id']);
    }

    public function test_incomplete_or_restricted_ancestry_never_creates_derived_numeric_nodes(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $registry = app(LocalLineageRegistry::class);
        $independent = $registry->registerSource(
            $owner->id, 'issuer_document', 'safe-A', hash('sha256', 'safe-A'), $this->source()
        );
        $restricted = $registry->registerSource(
            $owner->id, 'legacy_source', 'restricted-A', hash('sha256', 'restricted-A'),
            $this->source('MarketData.app BYOK')
        );
        $foreign = $registry->registerSource(
            $other->id, 'issuer_market_price', 'foreign-A', hash('sha256', 'foreign-A'), $this->source()
        );
        $analytics = app(DerivedAnalyticsLineageService::class);
        $base = ['ticker' => 'AAPL', 'source_name' => 'Independent evidence',
            'eps' => 1.5, 'pe_ratio' => 10, 'valuation_status' => 'AVAILABLE'];

        $blocked = $analytics->valuation($owner->id, 'safe-A', $base, $independent, $restricted, true);
        $this->assertSame('DENIED_RESTRICTED_ANCESTRY', $blocked['lineage_valuation_status']);
        $this->assertNull($blocked['lineage_valuation_node_id']);

        $missing = $analytics->valuation($owner->id, 'safe-A', $base, $independent, null, true);
        $this->assertSame('PENDING_INPUT_REVIEW', $missing['lineage_valuation_status']);

        $conflicted = $analytics->valuation($owner->id, 'safe-A', $base, $independent, $foreign, true, true);
        $this->assertSame('PENDING_INPUT_REVIEW', $conflicted['lineage_valuation_status']);
        $this->assertDatabaseCount('ii_lineage_edges', 0);

        $foreignDenied = $analytics->valuation(
            $owner->id, 'safe-A', $base, $independent, $foreign, true
        );
        $this->assertSame('FAILED_PARENT_INTEGRITY', $foreignDenied['lineage_valuation_status']);
        $this->assertNull($foreignDenied['lineage_valuation_node_id']);
        $this->assertDatabaseCount('ii_lineage_edges', 0);

        // Even if a caller attempts to forge a displayed lineage status,
        // no restricted or foreign-account node can become a score parent.
        $spoofed = $analytics->screener($owner->id, [
            ['ticker' => 'AAPL',
                'lineage_valuation_status' => 'REGISTERED_RESEARCH_ONLY',
                'lineage_valuation_node_id' => $restricted['id'],
                'quality_score' => 99.0],
            ['ticker' => 'MSFT',
                'lineage_valuation_status' => 'REGISTERED_RESEARCH_ONLY',
                'lineage_valuation_node_id' => $foreign['id'],
                'quality_score' => 95.0],
        ], []);
        foreach ($spoofed as $record) {
            $this->assertNull($record['lineage_screener_node_id']);
            $this->assertSame('PENDING_VALUATION_LINEAGE', $record['lineage_screener_status']);
        }

        $rows = $analytics->screener($owner->id, [
            $blocked + ['byok_lineage' => 'RESTRICTED_BYOK_SOURCE_DETECTED'],
            $missing,
        ], []);
        foreach ($rows as $row) {
            $this->assertNull($row['lineage_screener_node_id']);
            $this->assertSame('PENDING_VALUATION_LINEAGE', $row['lineage_screener_status']);
        }
    }
}
