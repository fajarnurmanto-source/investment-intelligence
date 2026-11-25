<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SignalInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MarketUniverseTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): User
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        config(['market_feed' => [
            'enabled' => true, 'provider' => 'twelve_data', 'owner_id' => $owner->id,
            'api_key' => 'fixture_key_not_real', 'source_name' => 'Twelve Data',
            'source_url' => 'https://api.twelvedata.com/time_series',
            'rights_reference' => 'TEST_ONLY', 'storage_permitted' => true,
            'automation_permitted' => true, 'currency' => 'USD', 'price_adjustment' => 'unadjusted',
            'session_timezone' => 'America/New_York', 'request_budget' => 2,
            'universe_json' => json_encode([
                ['ticker' => 'AAPL', 'provider_symbol' => 'AAPL', 'provider_mic' => 'XNAS'],
                ['ticker' => 'MSFT', 'provider_symbol' => 'MSFT', 'provider_mic' => 'XNAS'],
            ]),
        ]]);
        return $owner;
    }

    private function payload(string $symbol): array
    {
        $values = [];
        for ($i = 0; $i < 60; $i++) {
            $values[] = ['datetime' => (new \DateTimeImmutable('2024-01-01'))->modify('+'.$i.' days')->format('Y-m-d'),
                'open' => '100', 'high' => '103', 'low' => '99', 'close' => '101', 'volume' => '1000'];
        }
        return ['status' => 'ok', 'meta' => ['symbol' => $symbol, 'mic_code' => 'XNAS',
            'currency' => 'USD', 'exchange_timezone' => 'America/New_York', 'interval' => '1day'], 'values' => $values];
    }

    public function test_multiple_instruments_reuse_pipeline_and_can_be_selected(): void
    {
        $owner = $this->configure();
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            return Http::response($this->payload($query['symbol']));
        });
        $this->artisan('ii:sync-market-feed', ['--ticker' => 'msft'])->expectsOutput('MSFT|II_MARKET_FEED=IMPORTED')->assertSuccessful();
        Http::assertSentCount(1);
        $this->artisan('ii:sync-market-feed')->expectsOutput('AAPL|II_MARKET_FEED=IMPORTED')
            ->expectsOutput('MSFT|II_MARKET_FEED=UNCHANGED')->assertSuccessful();
        Http::assertSentCount(3);
        $this->assertCount(2, app(SignalInsightService::class)->list($owner->id));
    }

    public function test_budget_duplicate_and_credential_override_are_rejected_before_network(): void
    {
        $this->configure();
        Http::fake();
        config(['market_feed.request_budget' => 1]);
        $this->artisan('ii:sync-market-feed')->assertFailed();
        config(['market_feed.request_budget' => 2, 'market_feed.universe_json' => json_encode([
            ['ticker' => 'AAPL', 'provider_symbol' => 'AAPL', 'provider_mic' => 'XNAS'],
            ['ticker' => 'aapl', 'provider_symbol' => 'MSFT', 'provider_mic' => 'XNAS'],
        ])]);
        $this->artisan('ii:sync-market-feed')->assertFailed();
        config(['market_feed.universe_json' => json_encode([['ticker' => 'AAPL', 'api_key' => 'override']])]);
        $this->artisan('ii:sync-market-feed')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_rate_limit_stops_remaining_universe(): void
    {
        $owner = $this->configure();
        Http::fake(['api.twelvedata.com/*' => Http::response(['status' => 'error', 'code' => 429], 429)]);
        $this->artisan('ii:sync-market-feed')->expectsOutput('AAPL|II_PROVIDER_RATE_LIMITED')->assertFailed();
        Http::assertSentCount(1);
        $this->assertSame([], app(SignalInsightService::class)->list($owner->id));
    }
}
