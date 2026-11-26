<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AutomaticMarketUniverse;
use App\Services\MarketSecurityMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AutomaticMarketUniverseTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(string $mic, array $symbols): array
    {
        return [
            'provider' => 'twelve_data',
            'mic' => $mic,
            'status' => 'CATALOG_ONLY_ENTITLEMENT_UNVERIFIED',
            'candidates' => array_map(fn ($symbol) => [
                'provider_symbol' => $symbol, 'provider_mic' => $mic,
                'currency' => 'USD', 'session_timezone' => 'America/New_York',
            ], $symbols),
        ];
    }

    private function policy(int $owner): array
    {
        return ['owner_id' => $owner, 'provider' => 'twelve_data',
            'storage_permitted' => true, 'automation_permitted' => true,
            'rights_reference' => 'synthetic-test-policy',
            'daily_request_limit' => 3, 'request_budget' => 3];
    }

    public function test_collisions_owner_isolation_and_remaining_budget(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $master = app(MarketSecurityMaster::class);
        $master->ingest($owner->id, $this->catalog('XNAS', ['AAA', 'BBB', 'CCC']));
        $master->ingest($owner->id, $this->catalog('XNYS', ['AAA']));
        $master->ingest($other->id, $this->catalog('XNAS', ['DDD']));
        DB::table('ii_market_request_days')->insert([
            'provider' => 'twelve_data', 'utc_day' => now('UTC')->toDateString(), 'attempts' => 2,
        ]);
        $result = app(AutomaticMarketUniverse::class)->propose($owner->id, $this->policy($owner->id), ['XNAS']);
        $this->assertSame('PROPOSAL_ENTITLEMENT_UNVERIFIED', $result['status']);
        $this->assertSame(['BBB'], array_column($result['entries'], 'ticker'));
        $this->assertSame(1, $result['remaining_local_requests']);
        $this->assertDatabaseCount('ii_market_security_master', 5);
        $this->assertDatabaseCount('ii_market_feed_settings', 0);
    }

    public function test_empty_refresh_preserves_catalog_and_missing_symbol_is_stale(): void
    {
        $owner = User::factory()->create();
        $master = app(MarketSecurityMaster::class);
        $master->ingest($owner->id, $this->catalog('XNAS', ['AAA', 'BBB']));
        try {
            $master->ingest($owner->id, $this->catalog('XNAS', []));
            $this->fail('Empty catalog accepted');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('II_SECURITY_CATALOG_EMPTY', $exception->getMessage());
        }
        $this->assertDatabaseCount('ii_market_security_master', 2);
        $master->ingest($owner->id, $this->catalog('XNAS', ['BBB']));
        $this->assertDatabaseHas('ii_market_security_master', [
            'user_id' => $owner->id, 'symbol' => 'AAA', 'status' => 'NOT_SEEN_IN_LATEST_CATALOG',
        ]);
        $this->assertDatabaseHas('ii_market_security_master', [
            'user_id' => $owner->id, 'symbol' => 'BBB', 'status' => 'CATALOG_ONLY_ENTITLEMENT_UNVERIFIED',
        ]);
    }
}
