<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LicensedMarketFeedService;
use App\Services\SignalInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MarketHistoryCheckpointTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): User
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        config(['market_feed' => [
            'enabled' => true, 'provider' => 'twelve_data', 'owner_id' => $owner->id,
            'ticker' => 'AAPL', 'provider_symbol' => 'AAPL', 'provider_mic' => 'XNAS',
            'api_key' => 'fixture_key_not_real', 'source_name' => 'Twelve Data',
            'source_url' => 'https://api.twelvedata.com/time_series',
            'rights_reference' => 'TEST_ONLY', 'storage_permitted' => true,
            'automation_permitted' => true, 'currency' => 'USD', 'price_adjustment' => 'unadjusted',
            'session_timezone' => 'America/New_York', 'history_sessions' => 60,
        ]]);
        return $owner;
    }

    private function payload(int $offset, int $count, bool $conflict = false): array
    {
        $values = [];
        $start = new \DateTimeImmutable('2024-01-01');
        for ($i = $offset; $i < $offset + $count; $i++) {
            $price = $conflict ? 110 : 100;
            $values[] = ['datetime' => $start->modify('+'.$i.' days')->format('Y-m-d'),
                'open' => (string) $price, 'high' => (string) ($price + 3),
                'low' => (string) ($price - 1), 'close' => (string) ($price + 1), 'volume' => '1000'];
        }
        return ['status' => 'ok', 'meta' => ['symbol' => 'AAPL', 'mic_code' => 'XNAS',
            'currency' => 'USD', 'exchange_timezone' => 'America/New_York', 'interval' => '1day'], 'values' => $values];
    }

    public function test_short_incremental_window_preserves_existing_history(): void
    {
        $owner = $this->configure();
        Http::fake(['api.twelvedata.com/*' => Http::sequence()
            ->push($this->payload(0, 60))->push($this->payload(55, 6))->push($this->payload(55, 6))]);
        $feed = app(LicensedMarketFeedService::class);
        $this->assertSame('IMPORTED', $feed->sync());
        $this->assertSame('IMPORTED', $feed->sync());
        $this->assertSame('UNCHANGED', $feed->sync());
        $doc = app(SignalInsightService::class)->latestDocument($owner->id, 'AAPL');
        $this->assertCount(61, $doc['rows']);
        $this->assertSame('2024-01-01', $doc['coverage_first_date']);
        $this->assertSame('2024-03-01', $doc['coverage_last_date']);
        $this->assertSame('2024-02-15', $doc['acquisition_window']['start']);
        $this->assertCount(2, Storage::disk('local')->files('ii_signal_center/u'.$owner->id));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date=2024-02-15'));
    }

    public function test_expanding_history_target_requests_full_window_again(): void
    {
        $owner = $this->configure();
        Http::fake(['api.twelvedata.com/*' => Http::sequence()
            ->push($this->payload(30, 60))->push($this->payload(0, 90))]);
        $feed = app(LicensedMarketFeedService::class);
        $this->assertSame('IMPORTED', $feed->sync());
        config(['market_feed.history_sessions' => 1250]);
        $this->assertSame('IMPORTED', $feed->sync());
        $doc = app(SignalInsightService::class)->latestDocument($owner->id, 'AAPL');
        $this->assertCount(90, $doc['rows']);
        $this->assertNull($doc['acquisition_window']['start']);
        $this->assertSame(1250, $doc['acquisition_window']['limit']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'outputsize=1250') && !str_contains($request->url(), 'start_date='));
    }

    public function test_disconnected_and_conflicting_history_do_not_write_new_version(): void
    {
        $owner = $this->configure();
        Http::fake(['api.twelvedata.com/*' => Http::sequence()
            ->push($this->payload(0, 60))->push($this->payload(70, 2))->push($this->payload(55, 6, true))]);
        $this->artisan('ii:sync-market-feed')->assertSuccessful();
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_FEED_HISTORY_OVERLAP_REQUIRED')->assertFailed();
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_FEED_HISTORICAL_CONFLICT')->assertFailed();
        $this->assertCount(1, Storage::disk('local')->files('ii_signal_center/u'.$owner->id));
    }
}
