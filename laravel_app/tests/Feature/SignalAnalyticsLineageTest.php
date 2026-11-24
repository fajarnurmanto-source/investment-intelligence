<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LocalLineageRegistry;
use App\Services\PortfolioScenarioService;
use App\Services\SignalAnalyticsLineageService;
use App\Services\SignalInsightService;
use App\Services\StrategyReplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SignalAnalyticsLineageTest extends TestCase
{
    use RefreshDatabase;

    private function series(string $ticker, string $source = 'Independent local observations'): array
    {
        $rows = [];
        $start = new \DateTimeImmutable('2025-01-01');
        for ($i = 0; $i < 90; $i++) {
            $date = $start->modify('+'.$i.' days')->format('Y-m-d');
            $price = 100 + $i * 0.3 + sin($i / 4) * 2;
            $rows[] = [
                'date' => $date, 'open' => round($price, 3),
                'high' => round($price * 1.01, 3), 'low' => round($price * 0.99, 3),
                'close' => round($price + 0.2, 3), 'volume' => 1000 + $i,
            ];
        }

        return [
            'ticker' => $ticker, 'kind' => 'user_supplied',
            'source_name' => $source, 'source_url' => 'https://example.test/ohlcv',
            'currency' => 'USD', 'price_adjustment' => 'unadjusted',
            'session_timezone' => 'UTC', 'imported_at' => '2025-04-01',
            'rows' => $rows,
        ];
    }

    public function test_local_signal_files_bind_to_portfolio_and_strategy_derived_nodes(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $signals = app(SignalInsightService::class);
        $signals->save($owner->id, 'AAPL', $this->series('AAPL'));
        $signals->save($owner->id, 'MSFT', $this->series('MSFT'));
        $a = $signals->latestDocument($owner->id, 'AAPL');
        $b = $signals->latestDocument($owner->id, 'MSFT');
        $this->assertSame('REGISTERED', $a['lineage_source_status']);
        $this->assertSame('REGISTERED', $b['lineage_source_status']);
        $registry = app(LocalLineageRegistry::class);
        $this->assertTrue($registry->verifyTrace($owner->id, $a['lineage_source_node_id']));

        $docs = ['AAPL' => $a, 'MSFT' => $b];
        $weights = ['AAPL' => 50, 'MSFT' => 50];
        $portfolio = app(PortfolioScenarioService::class)->analyze($docs, $weights);
        $withLineage = app(SignalAnalyticsLineageService::class)->portfolio(
            $owner->id, $docs, $weights, $portfolio
        );
        $this->assertSame('REGISTERED_RESEARCH_ONLY', $withLineage['lineage_status']);
        $trace = $registry->trace($owner->id, $withLineage['lineage_node_id']);
        $this->assertCount(3, $trace['nodes']);
        $this->assertCount(2, $trace['edges']);
        $this->assertFalse($registry->allows($owner->id, $withLineage['lineage_node_id'], 'export'));

        $replay = app(StrategyReplayService::class)->replay($a, 10, 30, 15, 10);
        $strategy = app(SignalAnalyticsLineageService::class)->strategy(
            $owner->id, $a,
            ['fast'=>10, 'slow'=>30, 'fee_bps'=>15, 'slippage_bps'=>10], $replay
        );
        $this->assertSame('REGISTERED_RESEARCH_ONLY', $strategy['lineage_status']);
        $this->assertCount(2, $registry->trace($owner->id, $strategy['lineage_node_id'])['nodes']);
        $this->assertDatabaseCount('ii_lineage_nodes', 4);
        $again = app(SignalAnalyticsLineageService::class)->portfolio(
            $owner->id, $docs, $weights, $portfolio
        );
        $this->assertSame($withLineage['lineage_node_id'], $again['lineage_node_id']);
        foreach (DB::table('ii_lineage_nodes')->get() as $node) {
            $this->assertStringNotContainsString('100.2', $node->source_metadata);
        }
    }

    public function test_restricted_import_and_existing_restricted_json_never_feed_analysis(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $signals = app(SignalInsightService::class);
        try {
            $signals->save($owner->id, 'AAPL', $this->series('AAPL', 'MarketData.app BYOK'));
            $this->fail('Restricted BYOK may not be archived.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('II_BYOK_RESTRICTED_SOURCE_CANNOT_BE_IMPORTED', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('ii_signal_center/u'.$owner->id));
        $this->assertDatabaseCount('ii_lineage_nodes', 0);

        Storage::disk('local')->put(
            'ii_signal_center/u'.$owner->id.'/legacy.json',
            json_encode($this->series('AAPL', 'MarketData.app BYOK'), JSON_THROW_ON_ERROR)
        );
        $this->assertNull($signals->latestDocument($owner->id, 'AAPL'));
        $this->assertSame([], $signals->list($owner->id));
    }

    public function test_legacy_unregistered_source_and_foreign_owner_parent_fail_closed(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $signals = app(SignalInsightService::class);
        Storage::disk('local')->put(
            'ii_signal_center/u'.$owner->id.'/legacy.json',
            json_encode($this->series('AAPL'), JSON_THROW_ON_ERROR)
        );
        $unregistered = $signals->latestDocument($owner->id, 'AAPL');
        $this->assertSame('UNREGISTERED', $unregistered['lineage_source_status']);
        $replay = app(StrategyReplayService::class)->replay($unregistered, 10, 30, 15, 10);
        $res = app(SignalAnalyticsLineageService::class)->strategy(
            $owner->id, $unregistered,
            ['fast'=>10, 'slow'=>30, 'fee_bps'=>15, 'slippage_bps'=>10], $replay
        );
        $this->assertSame('PENDING_SOURCE_LINEAGE', $res['lineage_status']);
        $this->assertNull($res['lineage_node_id']);

        $signals->save($other->id, 'MSFT', $this->series('MSFT'));
        $foreign = $signals->latestDocument($other->id, 'MSFT');
        $forged = $foreign;
        $forged['ticker'] = 'AAPL';
        $res = app(SignalAnalyticsLineageService::class)->strategy(
            $owner->id, $forged,
            ['fast'=>10, 'slow'=>30, 'fee_bps'=>15, 'slippage_bps'=>10], $replay
        );
        $this->assertSame('PENDING_SOURCE_LINEAGE', $res['lineage_status']);
        $this->assertDatabaseCount('ii_lineage_nodes', 1);
    }
}
