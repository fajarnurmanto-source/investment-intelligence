<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LicensedMarketFeedService;
use App\Services\SignalInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class LicensedMarketFeedTest extends TestCase
{
    use RefreshDatabase;

    private function configureFeed(): User
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        config(['market_feed' => [
            'enabled' => true, 'owner_id' => $owner->id, 'ticker' => 'TEST1',
            'source_name' => 'Test fixture publisher', 'source_url' => 'https://example.test/feed',
            'rights_reference' => 'TEST_ONLY', 'storage_permitted' => true,
            'automation_permitted' => true, 'currency' => 'USD',
            'price_adjustment' => 'unadjusted', 'session_timezone' => 'UTC',
        ]]);
        return $owner;
    }

    private function writeFeed(User $owner, int $offset = 0, bool $conflict = false): void
    {
        $lines = ['date,open,high,low,close,volume'];
        $start = new \DateTimeImmutable('2024-01-01');
        for ($i = $offset; $i < $offset + 60; $i++) {
            $price = 100 + $i + ($conflict ? 1 : 0);
            $lines[] = implode(',', [$start->modify('+'.$i.' days')->format('Y-m-d'), $price, $price+2, $price-2, $price+1, 1000]);
        }
        Storage::disk('local')->put('ii_market_feed/u'.$owner->id.'/TEST1.csv', implode("\n", $lines)."\n");
    }

    public function test_licensed_feed_sync_is_idempotent_and_incremental(): void
    {
        $owner = $this->configureFeed();
        $service = app(LicensedMarketFeedService::class);
        $this->assertSame('WAITING_FOR_FEED', $service->sync());
        $this->writeFeed($owner);
        $this->assertSame('IMPORTED', $service->sync());
        $this->assertSame('UNCHANGED', $service->sync());
        $this->writeFeed($owner, 1);
        $this->assertSame('IMPORTED', $service->sync());
        $this->assertSame('UNCHANGED', $service->sync());
        $doc = app(SignalInsightService::class)->latestDocument($owner->id, 'TEST1');
        $this->assertCount(61, $doc['rows']);
        $this->assertSame('REGISTERED', $doc['lineage_source_status']);
        $this->assertFalse($doc['trading_authorized']);
        $this->assertSame('HOLD', $doc['decision']);
        $this->assertCount(2, Storage::disk('local')->files('ii_signal_center/u'.$owner->id));
        $this->assertNull(app(SignalInsightService::class)->latestDocument(User::factory()->create()->id, 'TEST1'));
    }

    public function test_restricted_rights_and_conflicting_history_are_rejected(): void
    {
        $owner = $this->configureFeed();
        $this->writeFeed($owner);
        config(['market_feed.storage_permitted' => false]);
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_FEED_RIGHTS_REQUIRED')->assertFailed();
        config(['market_feed.storage_permitted' => true, 'market_feed.source_name' => 'MarketData.app BYOK']);
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_BYOK_RESTRICTED_SOURCE_CANNOT_BE_IMPORTED')->assertFailed();
        config(['market_feed.source_name' => 'Test fixture publisher']);
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_MARKET_FEED=IMPORTED')->assertSuccessful();
        $this->writeFeed($owner, 1, true);
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_FEED_HISTORICAL_CONFLICT')->assertFailed();
        $this->assertCount(1, Storage::disk('local')->files('ii_signal_center/u'.$owner->id));
    }

    public function test_disabled_default_does_not_fetch_or_write(): void
    {
        config(['market_feed.enabled' => false]);
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_MARKET_FEED=DISABLED')->assertSuccessful();
    }
}
