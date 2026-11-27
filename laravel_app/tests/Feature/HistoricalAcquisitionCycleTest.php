<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HistoricalAcquisitionCycle;
use App\Services\MarketFeedSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class HistoricalAcquisitionCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_opt_in_backoff_resume_and_policy_revocation(): void
    {
        Storage::fake('local');
        config(['market_feed.owner_id' => null, 'market_feed.enabled' => false]);
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'analyst'])->save();
        app(MarketFeedSettings::class)->initializeOwner($owner->id);
        $cycle = app(HistoricalAcquisitionCycle::class);
        Http::preventStrayRequests();
        Http::fake(['https://raw.githubusercontent.com/wildangunawan/Dataset-Saham-IDX/*' => Http::response('', 503)]);
        $this->assertSame('DISABLED', $cycle->tick($owner->id)['state']);
        $this->artisan('ii:historical-cycle', ['action' => 'enable', '--user' => $owner->id])->assertFailed();
        Http::assertNothingSent();
        $cycle->enable($owner->id, true);
        $this->assertDatabaseHas('ii_historical_cycles', ['user_id' => $owner->id, 'enabled' => true, 'state' => 'READY']);
        $first = $cycle->tick($owner->id);
        $this->assertSame('BACKOFF', $first['state']);
        $this->assertSame(0, (int) $first['cursor']);
        Http::assertSentCount(1);
        $this->assertSame('BACKOFF', $cycle->tick($owner->id)['state']);
        Http::assertSentCount(1);
        $this->travel(16)->minutes();
        $second = $cycle->tick($owner->id);
        $this->assertSame(2, (int) $second['attempts']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ii_historical_cycle_receipts', 0);
        $owner->forceFill(['ii_research_role' => 'viewer'])->save();
        $this->assertSame('POLICY_PAUSED', $cycle->tick($owner->id)['state']);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('ii_historical_cycles', ['user_id' => $owner->id, 'enabled' => false]);
    }

    public function test_recovery_imports_then_reuses_archive_and_completes(): void
    {
        Storage::fake('local');
        config(['market_feed.owner_id' => null, 'market_feed.enabled' => false]);
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'analyst'])->save();
        app(MarketFeedSettings::class)->initializeOwner($owner->id);
        $entries = app(\App\Services\IdxHistoricalCatalog::class)->entries();
        ksort($entries);
        $tickers = array_keys($entries);
        $offset = array_search('BBCA', $tickers, true);
        $this->assertIsInt($offset);
        $lines = ['date,open_price,high,low,close,volume,listed_shares'];
        $start = new \DateTimeImmutable('2024-01-01');
        for ($i = 0; $i < 250; $i++) {
            $lines[] = $start->modify('+'.$i.' days')->format('Y-m-d').',100,102,98,101,1000,1000';
        }
        $raw = implode("\n", $lines)."\n";
        $snapshot = \App\Services\IdxHistoricalSnapshotSource::normalize($raw) + [
            'ticker' => 'BBCA', 'source_name' => 'Synthetic cycle fixture',
            'source_url' => 'https://example.test/historical',
            'source_commit' => \App\Services\IdxHistoricalSnapshotSource::COMMIT,
            'source_blob' => \App\Services\IdxHistoricalSnapshotSource::BLOB,
            'source_sha256' => hash('sha256', $raw), 'provider_identity' => 'BBCA@XIDX',
        ];
        $source = new class($snapshot) implements \App\Services\HistoricalMarketSnapshotSource {
            public bool $offline = true;
            public int $calls = 0;
            public function __construct(private array $data) {}
            public function snapshot(): array
            {
                $this->calls++;
                if ($this->offline) {
                    throw new \RuntimeException('II_IDX_SNAPSHOT_NETWORK_ERROR');
                }
                return $this->data;
            }
        };
        $this->app->instance(\App\Services\HistoricalMarketSnapshotSource::class, $source);
        $cycle = app(HistoricalAcquisitionCycle::class);
        $cycle->enable($owner->id, true);
        // Position the disposable fixture at BBCA, not a claim about earlier candidates.
        DB::table('ii_historical_cycles')->where('user_id', $owner->id)->update(['cursor' => $offset]);
        $this->assertSame('BACKOFF', $cycle->tick($owner->id, 1)['state']);
        $this->assertSame(1, $source->calls);
        $this->assertSame($offset, (int) $cycle->status($owner->id)['cursor']);
        $this->assertDatabaseCount('ii_historical_cycle_receipts', 0);
        $source->offline = false;
        $this->assertSame('BACKOFF', $cycle->tick($owner->id, 1)['state']);
        $this->assertSame(1, $source->calls);
        $this->travel(16)->minutes();
        $resumed = app(HistoricalAcquisitionCycle::class)->tick($owner->id, 1);
        $this->assertSame($offset + 1, (int) $resumed['cursor']);
        $this->assertSame(1, (int) $resumed['accepted']);
        $this->assertSame(0, (int) $resumed['attempts']);
        $this->assertSame(2, $source->calls);
        $this->assertDatabaseHas('ii_historical_cycle_receipts', ['user_id' => $owner->id, 'ticker' => 'BBCA', 'status' => 'IMPORTED']);
        $doc = app(\App\Services\SignalInsightService::class)->latestDocument($owner->id, 'BBCA');
        $this->assertSame('REGISTERED', $doc['lineage_source_status']);
        $this->assertFalse($doc['trading_authorized']);
        $this->assertCount(250, $doc['rows']);
        // Simulate interrupted checkpoint persistence after the immutable import.
        DB::table('ii_historical_cycles')->where('user_id', $owner->id)->update(['cursor' => $offset, 'accepted' => 0]);
        $cycle->tick($owner->id, 1);
        $this->assertSame(2, $source->calls);
        $this->assertDatabaseCount('ii_historical_cycle_receipts', 1);
        $this->assertDatabaseHas('ii_historical_cycle_receipts', ['ticker' => 'BBCA', 'status' => 'LOCAL_VERIFIED']);
        $this->assertCount(1, Storage::disk('local')->files('ii_signal_center/u'.$owner->id));
        DB::table('ii_historical_cycles')->where('user_id', $owner->id)->update(['cursor' => count($tickers)]);
        $this->assertSame('COMPLETE', $cycle->tick($owner->id, 1)['state']);
        $this->assertDatabaseHas('ii_historical_cycles', ['user_id' => $owner->id, 'enabled' => false]);
    }
}
