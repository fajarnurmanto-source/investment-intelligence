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
}
