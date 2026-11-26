<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MarketFeedSettings;
use App\Services\MarketDiscoveryWorkflow;
use App\Services\MarketProviderCreditGuard;
use App\Services\MarketSecurityMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MarketOperationalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function setupPolicy(array $mics = ['XNAS'], int $budget = 10): User
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        Storage::fake('local');
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'admin'])->save();
        $settings = app(MarketFeedSettings::class);
        $settings->savePolicy($owner->id, ['mics' => $mics, 'daily_request_limit' => $budget,
            'rights_reference' => 'synthetic-only', 'storage_permitted' => true, 'automation_permitted' => true]);
        $settings->replaceCredential($owner->id, 'synthetic-test-key');
        $settings->authorizeDiscovery($owner->id);
        return $owner;
    }

    private function runDiscovery(User $owner): array
    {
        $cfg = app(MarketFeedSettings::class)->current();
        return app(MarketDiscoveryWorkflow::class)->run($owner->id, $cfg, $cfg['mics']);
    }

    private function catalog(string $mic, string $symbol): array
    {
        return ['provider' => 'twelve_data', 'mic' => $mic, 'status' => 'CATALOG_ONLY_ENTITLEMENT_UNVERIFIED',
            'candidates' => [['provider_symbol' => $symbol, 'provider_mic' => $mic,
                'currency' => 'USD', 'session_timezone' => 'America/New_York']]];
    }

    public function test_freshness_boundary_and_unseen_market_priority(): void
    {
        $owner = $this->setupPolicy(['XNAS', 'XNYS'], 3);
        $master = app(MarketSecurityMaster::class);
        $master->ingest($owner->id, $this->catalog('XNAS', 'AAA'));
        $this->travel(72)->hours();
        $this->assertSame(1, $master->freshCount($owner->id, 'XNAS'));
        $this->travel(1)->seconds();
        $this->assertSame(0, $master->freshCount($owner->id, 'XNAS'));
        Http::fake([
            'https://api.twelvedata.com/exchanges*' => Http::response(['status' => 'ok', 'data' => [
                ['code' => 'XNYS', 'timezone' => 'America/New_York']]]),
            'https://api.twelvedata.com/stocks*' => Http::response(['status' => 'ok', 'data' => [
                ['mic_code' => 'XNYS', 'symbol' => 'BBB', 'currency' => 'USD', 'type' => 'Common Stock']]]),
        ]);
        $result = $this->runDiscovery($owner);
        $this->assertSame(['XNYS' => 1], $result['catalog_counts']);
        $this->assertSame(['XNAS' => 'BUDGET_DEFERRED'], $result['deferred_mics']);
        Http::assertSentCount(2);
    }

    public function test_temporary_failure_preserves_catalog_and_next_run_recovers(): void
    {
        $owner = $this->setupPolicy();
        $master = app(MarketSecurityMaster::class);
        $master->ingest($owner->id, $this->catalog('XNAS', 'AAA'));
        $this->travel(73)->hours();
        $before = $master->lastObservation($owner->id, 'XNAS');
        Http::fake(['https://api.twelvedata.com/exchanges*' => Http::sequence()
            ->push([], 503)->push(['status' => 'ok', 'data' => [['code' => 'XNAS', 'timezone' => 'America/New_York']]]),
            'https://api.twelvedata.com/stocks*' => Http::response(['status' => 'ok', 'data' => [
                ['mic_code' => 'XNAS', 'symbol' => 'AAA', 'currency' => 'USD', 'type' => 'Common Stock']]])]);
        $first = $this->runDiscovery($owner);
        $this->assertSame(['XNAS' => 'II_PROVIDER_TEMPORARY_ERROR'], $first['deferred_mics']);
        $this->assertSame($before, $master->lastObservation($owner->id, 'XNAS'));
        $second = $this->runDiscovery($owner);
        $this->assertSame([], $second['deferred_mics']);
        $this->assertSame(['XNAS' => 1], $second['catalog_counts']);
        $this->assertNotSame($before, $master->lastObservation($owner->id, 'XNAS'));
        Http::assertSentCount(3);
        $this->assertSame(3, (int) DB::table('ii_market_request_days')->where('utc_day', now('UTC')->toDateString())->value('attempts'));
    }

    public function test_json_rate_limit_stops_other_markets_and_credit_expires(): void
    {
        $owner = $this->setupPolicy(['XNAS', 'XNYS']);
        Http::fake(['https://api.twelvedata.com/*' => Http::response(['status' => 'error', 'code' => 429], 200)]);
        $result = $this->runDiscovery($owner);
        $this->assertSame('II_PROVIDER_RATE_LIMITED', $result['deferred_mics']['XNAS']);
        $this->assertSame('NOT_ATTEMPTED_AFTER_PROVIDER_FAILURE', $result['deferred_mics']['XNYS']);
        $this->assertSame(0, app(MarketProviderCreditGuard::class)->remaining($owner->id));
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->assertNull(app(MarketProviderCreditGuard::class)->remaining($owner->id));
    }

    public function test_credit_debit_does_not_extend_observation_window(): void
    {
        $guard = app(MarketProviderCreditGuard::class);
        $guard->observe(1, new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, ['api-credits-left' => '2'])));
        $this->travel(30)->seconds();
        $guard->debit(1);
        $this->assertSame(1, $guard->remaining(1));
        $guard->debit(1);
        $this->assertSame(0, $guard->remaining(1));
        $this->travel(31)->seconds();
        $this->assertNull($guard->remaining(1));
    }
}
