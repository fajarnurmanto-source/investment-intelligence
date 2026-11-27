<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HistoricalMarketSnapshotSource;
use App\Services\IdxHistoricalSnapshotSource;
use App\Services\MarketFeedSettings;
use App\Services\SignalInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class HistoricalSignalBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        Storage::fake('local');
        config(['market_feed.owner_id' => null, 'market_feed.enabled' => false]);
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'analyst'])->save();
        app(MarketFeedSettings::class)->initializeOwner($owner->id);
        return $owner;
    }

    private function csv(bool $changed = false): string
    {
        $lines = ['date,open_price,high,low,close,volume,listed_shares'];
        $start = new \DateTimeImmutable('2024-01-01');
        for ($i = 0; $i < 250; $i++) {
            $lines[] = implode(',', [$start->modify('+'.$i.' days')->format('Y-m-d'), 100, 102, 98, 101, 1000,
                $changed && $i === 249 ? 2000 : 1000]);
        }
        return implode("\n", $lines)."\n";
    }

    public function test_source_checksum_and_rights_gate_precede_any_import(): void
    {
        $owner = $this->owner();
        Http::fake([IdxHistoricalSnapshotSource::RAW_URL => Http::response($this->csv())]);
        $this->artisan('ii:bootstrap-idx-history', ['--user' => $owner->id, '--import' => true])
            ->expectsOutput('II_HISTORICAL_RIGHTS_CONFIRMATION_REQUIRED')->assertFailed();
        Http::assertNothingSent();
        $this->artisan('ii:bootstrap-idx-history', ['--user' => $owner->id, '--rights-confirmed' => true, '--import' => true])
            ->expectsOutput('II_IDX_SNAPSHOT_BLOB_MISMATCH')->assertFailed();
        Http::assertSentCount(1);
        $this->assertNull(app(SignalInsightService::class)->latestDocument($owner->id, 'BBCA'));
    }

    public function test_allowlist_and_per_ticker_checksum_fail_closed(): void
    {
        $owner = $this->owner();
        Http::preventStrayRequests();
        $url = str_replace('BBCA.csv', 'BBRI.csv', IdxHistoricalSnapshotSource::RAW_URL);
        Http::fake([$url => Http::response($this->csv())]);
        $args = ['--user' => $owner->id, '--rights-confirmed' => true, '--import' => true];
        $this->artisan('ii:bootstrap-idx-history', $args + ['--ticker' => '../BAD'])
            ->expectsOutput('II_IDX_SNAPSHOT_TICKER_UNSUPPORTED')->assertFailed();
        Http::assertNothingSent();
        $this->artisan('ii:bootstrap-idx-history', $args + ['--ticker' => 'BBRI'])
            ->expectsOutput('II_IDX_SNAPSHOT_BLOB_MISMATCH')->assertFailed();
        Http::assertSentCount(1);
        $this->assertNull(app(SignalInsightService::class)->latestDocument($owner->id, 'BBRI'));
        $this->assertCount(17, IdxHistoricalSnapshotSource::BLOBS);
        foreach (['ADRO', 'INCO', 'JSMR'] as $excluded) {
            $this->assertArrayNotHasKey($excluded, IdxHistoricalSnapshotSource::BLOBS);
        }
    }

    public function test_network_outage_recovers_registered_archive_without_reporting_fresh_acquisition(): void
    {
        $owner = $this->owner();
        $raw = $this->csv();
        $snapshot = IdxHistoricalSnapshotSource::normalize($raw) + [
            'ticker' => 'BBCA', 'source_name' => 'Synthetic recovery fixture',
            'source_url' => 'https://example.test/historical',
            'source_commit' => IdxHistoricalSnapshotSource::COMMIT, 'source_blob' => IdxHistoricalSnapshotSource::BLOB,
            'source_sha256' => hash('sha256', $raw), 'provider_identity' => 'BBCA@XIDX',
        ];
        $source = new class($snapshot) implements HistoricalMarketSnapshotSource {
            public bool $offline = false;
            public function __construct(private array $data) {}
            public function snapshot(): array
            {
                if ($this->offline) {
                    throw new \RuntimeException('II_IDX_SNAPSHOT_NETWORK_ERROR');
                }
                return $this->data;
            }
        };
        $this->app->instance(HistoricalMarketSnapshotSource::class, $source);
        $args = ['--user' => $owner->id, '--rights-confirmed' => true, '--import' => true];
        $this->artisan('ii:bootstrap-idx-history', $args)->expectsOutput('II_HISTORICAL_BOOTSTRAP=IMPORTED')->assertSuccessful();
        $source->offline = true;
        $this->artisan('ii:bootstrap-idx-history', $args)->expectsOutput('II_HISTORICAL_BOOTSTRAP=OFFLINE_ARCHIVE')->assertFailed();
        $this->assertCount(1, Storage::disk('local')->files('ii_signal_center/u'.$owner->id));
        $this->actingAs($owner)->get('/ii/signals')->assertOk()->assertSee('BBCA')->assertSee('STALE');
        $source->offline = false;
        $this->artisan('ii:bootstrap-idx-history', $args)->expectsOutput('II_HISTORICAL_BOOTSTRAP=UNCHANGED')->assertSuccessful();
    }

    public function test_catalog_candidates_have_pinned_identity_and_fail_closed(): void
    {
        $owner = $this->owner();
        $catalog = app(\App\Services\IdxHistoricalCatalog::class);
        $this->assertCount(955, $catalog->entries());
        $this->assertSame('94e11159c534b29d7c853e1d98224debd26f0c7a', $catalog->candidate('SIDO')['blob']);
        $this->assertSame('Saham/LQ45/BBCA.csv', $catalog->resolve('BBCA')['path']);
        Http::preventStrayRequests();
        $url = 'https://raw.githubusercontent.com/wildangunawan/Dataset-Saham-IDX/'.IdxHistoricalSnapshotSource::COMMIT.'/Saham/Semua/SIDO.csv';
        Http::fake([$url => Http::response($this->csv())]);
        $args = ['--user' => $owner->id, '--ticker' => 'SIDO', '--import' => true];
        $this->artisan('ii:bootstrap-idx-history', $args)->expectsOutput('II_HISTORICAL_RIGHTS_CONFIRMATION_REQUIRED')->assertFailed();
        Http::assertNothingSent();
        $this->artisan('ii:bootstrap-idx-history', $args + ['--rights-confirmed' => true])
            ->expectsOutput('II_IDX_SNAPSHOT_BLOB_MISMATCH')->assertFailed();
        Http::assertSentCount(1);
        $this->assertNull(app(SignalInsightService::class)->latestDocument($owner->id, 'SIDO'));
        $this->artisan('ii:discover-idx-history', ['--user' => $owner->id, '--limit' => 21, '--rights-confirmed' => true])
            ->expectsOutput('II_IDX_CATALOG_POLICY_REQUIRED')->assertFailed();
    }

    public function test_normalizer_rejects_share_basis_changes(): void
    {
        $this->expectExceptionMessage('II_IDX_SNAPSHOT_SHARE_BASIS_CHANGED');
        IdxHistoricalSnapshotSource::normalize($this->csv(true));
    }

    public function test_historical_preview_import_idempotence_and_signal_rendering(): void
    {
        $owner = $this->owner();
        $other = User::factory()->create();
        $other->forceFill(['ii_research_role' => 'analyst'])->save();
        $raw = $this->csv();
        $snapshot = IdxHistoricalSnapshotSource::normalize($raw) + [
            'ticker' => 'BBCA', 'source_name' => 'Synthetic integration fixture',
            'source_url' => 'https://example.test/historical',
            'source_commit' => 'fixture', 'source_blob' => IdxHistoricalSnapshotSource::BLOB,
            'source_sha256' => hash('sha256', $raw), 'provider_identity' => 'BBCA@XIDX',
        ];
        $this->app->instance(HistoricalMarketSnapshotSource::class, new class($snapshot) implements HistoricalMarketSnapshotSource {
            public function __construct(private array $data) {}
            public function snapshot(): array { return $this->data; }
        });
        $args = ['--user' => $owner->id, '--rights-confirmed' => true];
        $this->artisan('ii:bootstrap-idx-history', array_replace($args, ['--user' => $other->id]))
            ->expectsOutput('II_HISTORICAL_OWNER_REQUIRED')->assertFailed();
        $this->artisan('ii:bootstrap-idx-history', $args)->expectsOutput('II_HISTORICAL_BOOTSTRAP=PREVIEW')->assertSuccessful();
        $signals = app(SignalInsightService::class);
        $this->assertNull($signals->latestDocument($owner->id, 'BBCA'));
        $args['--import'] = true;
        $this->artisan('ii:bootstrap-idx-history', $args)->expectsOutput('II_HISTORICAL_BOOTSTRAP=IMPORTED')->assertSuccessful();
        $this->artisan('ii:bootstrap-idx-history', $args)->expectsOutput('II_HISTORICAL_BOOTSTRAP=UNCHANGED')->assertSuccessful();
        $doc = $signals->latestDocument($owner->id, 'BBCA');
        $this->assertCount(250, $doc['rows']);
        $this->assertSame('REGISTERED', $doc['lineage_source_status']);
        $this->assertSame('STALE', $signals->insight($doc)['state']);
        $this->assertSame('HOLD', $doc['decision']);
        $this->assertFalse($doc['trading_authorized']);
        $this->assertNull($signals->latestDocument($other->id, 'BBCA'));
        $this->assertCount(1, Storage::disk('local')->files('ii_signal_center/u'.$owner->id));
        $this->actingAs($owner)->get('/ii/signals')->assertOk()->assertSee('BBCA')->assertSee('STALE');
        $this->assertFalse(app(MarketFeedSettings::class)->current()['enabled']);
        $this->assertFalse(app(MarketFeedSettings::class)->current()['discovery_enabled']);
    }
}
