<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LicensedMarketFeedService;
use App\Services\SignalInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TwelveDataFeedTest extends TestCase
{
    use RefreshDatabase;

    private function setupFeed(): User
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
            'automation_permitted' => true, 'currency' => 'USD',
            'price_adjustment' => 'unadjusted', 'session_timezone' => 'America/New_York',
        ]]);
        return $owner;
    }

    private function payload(): array
    {
        $values = [];
        for ($i = 0; $i < 60; $i++) {
            $values[] = ['datetime' => (new \DateTimeImmutable('2024-01-01'))->modify('+'.$i.' days')->format('Y-m-d'),
                'open' => '100', 'high' => '103', 'low' => '99', 'close' => '101', 'volume' => '1000'];
        }
        return ['status' => 'ok', 'meta' => ['symbol' => 'AAPL', 'mic_code' => 'XNAS',
            'currency' => 'USD', 'exchange_timezone' => 'America/New_York', 'interval' => '1day'], 'values' => $values];
    }

    public function test_provider_feeds_existing_signal_pipeline_and_preserves_rights(): void
    {
        $owner = $this->setupFeed();
        Http::fake(['api.twelvedata.com/*' => Http::response($this->payload())]);
        $service = app(LicensedMarketFeedService::class);
        $this->assertSame('IMPORTED', $service->sync());
        $this->assertSame('UNCHANGED', $service->sync());
        $doc = app(SignalInsightService::class)->latestDocument($owner->id, 'AAPL');
        $this->assertSame('LICENSED_PROVIDER_API', $doc['acquisition_mode']);
        $this->assertSame('AAPL@XNAS', $doc['provider_identity']);
        $this->assertSame('REGISTERED', $doc['lineage_source_status']);
        $this->assertCount(60, $doc['rows']);
        $this->assertFalse($doc['trading_authorized']);
        $this->assertStringNotContainsString('fixture_key_not_real', json_encode($doc));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'apikey fixture_key_not_real')
            && str_contains($request->url(), 'adjust=none') && !str_contains($request->url(), 'fixture_key_not_real'));
    }

    public function test_missing_rights_prevents_network_and_wrong_exchange_is_rejected(): void
    {
        $owner = $this->setupFeed();
        $payload = $this->payload();
        $payload['meta']['mic_code'] = 'XNYS';
        Http::fake(['api.twelvedata.com/*' => Http::response($payload)]);
        config(['market_feed.automation_permitted' => false]);
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_FEED_RIGHTS_REQUIRED')->assertFailed();
        Http::assertNothingSent();
        config(['market_feed.automation_permitted' => true]);
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_PROVIDER_IDENTITY_MISMATCH')->assertFailed();
        $this->assertNull(app(SignalInsightService::class)->latestDocument($owner->id, 'AAPL'));
    }

    public function test_rate_limit_does_not_create_data(): void
    {
        $owner = $this->setupFeed();
        Http::fake(['api.twelvedata.com/*' => Http::response(['status' => 'error', 'code' => 429], 429)]);
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_PROVIDER_RATE_LIMITED')->assertFailed();
        $this->assertNull(app(SignalInsightService::class)->latestDocument($owner->id, 'AAPL'));
    }
}
