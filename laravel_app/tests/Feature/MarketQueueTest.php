<?php

namespace Tests\Feature;

use App\Jobs\SyncMarketUniverse;
use App\Models\User;
use App\Services\LicensedMarketFeedService;
use App\Services\LocalByokCredentialVault;
use App\Services\MarketAcquisitionLedger;
use App\Services\MarketUniverseRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class MarketQueueTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): User
    {
        $owner = User::factory()->create();
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 180,
            'market_feed' => [
                'enabled' => true, 'owner_id' => $owner->id, 'provider' => 'twelve_data',
                'ticker' => 'AAPL', 'provider_symbol' => 'AAPL', 'provider_mic' => 'XNAS',
                'source_name' => 'Twelve Data', 'source_url' => 'https://api.twelvedata.com/time_series',
                'rights_reference' => 'TEST_ONLY', 'storage_permitted' => true, 'automation_permitted' => true,
                'currency' => 'USD', 'price_adjustment' => 'unadjusted', 'session_timezone' => 'America/New_York',
            ]]);
        app(LocalByokCredentialVault::class)->save($owner->id, 'twelve_data', 'fixture_key_not_real');
        return $owner;
    }

    public function test_dispatch_contains_identity_not_credentials(): void
    {
        $owner = $this->configure();
        Queue::fake();
        Http::fake();
        $this->artisan('ii:sync-market-feed', ['--queue' => true])->assertSuccessful();
        Queue::assertPushed(SyncMarketUniverse::class, function ($job) use ($owner) {
            $this->assertStringNotContainsString('fixture_key_not_real', serialize($job));
            return $job->ownerId === $owner->id && $job->ticker === 'AAPL';
        });
        Http::assertNothingSent();
    }

    public function test_worker_rechecks_enabled_and_owner(): void
    {
        $owner = $this->configure();
        Http::fake();
        $job = new SyncMarketUniverse($owner->id, 'AAPL');
        config(['market_feed.enabled' => false]);
        app()->call([$job, 'handle']);
        config(['market_feed.enabled' => true, 'market_feed.owner_id' => $owner->id + 1]);
        app()->call([$job, 'handle']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ii_market_acquisition_receipts', 0);
    }

    public function test_transient_failure_is_retryable_and_charged(): void
    {
        $owner = $this->configure();
        Http::fake(['api.twelvedata.com/*' => Http::response([], 503)]);
        $job = new SyncMarketUniverse($owner->id, 'AAPL');
        $this->assertSame([60, 300], $job->backoff());
        try {
            app()->call([$job, 'handle']);
            $this->fail('Expected transient failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('II_MARKET_JOB_TRANSIENT', $exception->getMessage());
        }
        $this->assertDatabaseHas('ii_market_acquisition_receipts', ['status' => 'II_PROVIDER_TEMPORARY_ERROR']);
        $this->assertSame(1, (int) DB::table('ii_market_request_days')->value('attempts'));
    }

    public function test_budget_dispatch_prioritizes_unattempted_and_never_exceeds_credit(): void
    {
        $owner = $this->configure();
        $items = array_map(fn ($ticker) => ['ticker' => $ticker, 'provider_symbol' => $ticker,
            'provider_mic' => 'XNAS', 'currency' => 'USD', 'price_adjustment' => 'unadjusted',
            'session_timezone' => 'America/New_York'], ['AAA', 'BBB', 'CCC']);
        config(['market_feed.universe_json' => json_encode($items), 'market_feed.request_budget' => 3,
            'market_feed.daily_request_limit' => 3]);
        DB::table('ii_market_acquisition_receipts')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $owner->id,
            'ticker' => 'AAA', 'provider' => 'twelve_data', 'status' => 'IMPORTED', 'started_at' => now('UTC'),
        ]);
        app(MarketAcquisitionLedger::class)->reserve(config('market_feed'));
        app(\App\Services\MarketProviderCreditGuard::class)->observe($owner->id,
            new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, ['api-credits-left' => '1'])));
        Queue::fake();
        Http::fake();
        $this->artisan('ii:sync-market-feed', ['--queue' => true])->assertSuccessful();
        Queue::assertPushed(SyncMarketUniverse::class, 1);
        Queue::assertPushed(SyncMarketUniverse::class, fn ($job) => $job->ticker === 'BBB');
        app(\App\Services\MarketProviderCreditGuard::class)->markExhausted($owner->id);
        $this->artisan('ii:sync-market-feed', ['--queue' => true])->expectsOutput('II_MARKET_FEED=DEFERRED_BUDGET')->assertSuccessful();
        Queue::assertPushed(SyncMarketUniverse::class, 1);
        Http::assertNothingSent();
    }

    public function test_unsafe_queue_timeout_is_rejected(): void
    {
        $this->configure();
        config(['queue.connections.database.retry_after' => 90]);
        Queue::fake();
        $this->artisan('ii:sync-market-feed', ['--queue' => true])
            ->expectsOutput('II_MARKET_QUEUE_CONFIGURATION_INVALID')->assertFailed();
        Queue::assertNothingPushed();
    }
    public function test_interrupted_receipts_are_reconciled_without_releasing_quota(): void
    {
        $owner = $this->configure();
        $ledger = app(MarketAcquisitionLedger::class);
        $ledger->reserve(['daily_request_limit' => 10]);
        foreach ([3, 1] as $hours) {
            DB::table('ii_market_acquisition_receipts')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $owner->id,
                'ticker' => 'AAPL', 'provider' => 'twelve_data', 'status' => 'RUNNING',
                'started_at' => now('UTC')->subHours($hours),
            ]);
        }
        $this->assertSame(1, $ledger->reconcileInterrupted());
        $this->assertSame(0, $ledger->reconcileInterrupted());
        $this->assertDatabaseHas('ii_market_acquisition_receipts', ['status' => 'INTERRUPTED_OUTCOME_UNKNOWN']);
        $this->assertDatabaseHas('ii_market_acquisition_receipts', ['status' => 'RUNNING']);
        $this->assertSame(1, (int) DB::table('ii_market_request_days')->value('attempts'));
    }
}
