<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MarketFeedSettings;
use App\Services\SignalInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DirectMarketAcquisitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_path_without_universe_and_scope_and_identity_guards(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'analyst'])->save();
        $settings = app(MarketFeedSettings::class);
        $settings->initializeOwner($owner->id);
        $settings->savePolicy($owner->id, ['mics' => ['XNAS'], 'daily_request_limit' => 3,
            'rights_reference' => 'TEST_ONLY', 'storage_permitted' => true, 'automation_permitted' => true]);
        $settings->replaceCredential($owner->id, 'fixture_direct_key');
        $values = [];
        for ($i = 0; $i < 60; $i++) {
            $values[] = ['datetime' => (new \DateTimeImmutable('2024-01-01'))->modify('+'.$i.' days')->format('Y-m-d'),
                'open' => '100', 'high' => '102', 'low' => '98', 'close' => '101', 'volume' => '1000'];
        }
        $payload = ['status' => 'ok', 'meta' => ['symbol' => 'AAPL', 'mic_code' => 'XNAS', 'currency' => 'USD',
            'exchange_timezone' => 'America/New_York', 'interval' => '1day'], 'values' => $values];
        $wrong = $payload;
        $wrong['meta']['mic_code'] = 'XNYS';
        Http::fake(['https://api.twelvedata.com/time_series*' => Http::sequence()->push($wrong)->push($payload)->push($payload)]);
        $args = ['--user' => $owner->id, '--symbol' => 'AAPL', '--mic' => 'XNAS',
            '--currency' => 'USD', '--timezone' => 'America/New_York', '--confirm' => true];
        $this->artisan('ii:acquire-direct-market', array_replace($args, ['--confirm' => false]))
            ->expectsOutput('II_DIRECT_CONFIRMATION_REQUIRED')->assertFailed();
        $this->artisan('ii:acquire-direct-market', array_replace($args, ['--mic' => 'XIDX']))
            ->expectsOutput('II_DIRECT_MIC_OUT_OF_SCOPE')->assertFailed();
        Http::assertNothingSent();
        $this->artisan('ii:acquire-direct-market', $args)->expectsOutput('II_PROVIDER_IDENTITY_MISMATCH')->assertFailed();
        $this->assertNull(app(SignalInsightService::class)->latestDocument($owner->id, 'AAPL'));
        $this->artisan('ii:acquire-direct-market', $args)->expectsOutput('II_DIRECT_ACQUISITION=IMPORTED')->assertSuccessful();
        $this->artisan('ii:acquire-direct-market', $args)->expectsOutput('II_DIRECT_ACQUISITION=UNCHANGED')->assertSuccessful();
        $this->artisan('ii:acquire-direct-market', $args)->expectsOutput('II_PROVIDER_DAILY_BUDGET_EXHAUSTED')->assertFailed();
        Http::assertSentCount(3);
        $this->assertSame('[]', $settings->current()['universe_json']);
        $this->assertFalse($settings->current()['enabled']);
        $this->assertFalse($settings->current()['discovery_enabled']);
        $this->assertFalse(app(SignalInsightService::class)->latestDocument($owner->id, 'AAPL')['trading_authorized']);
    }
}
