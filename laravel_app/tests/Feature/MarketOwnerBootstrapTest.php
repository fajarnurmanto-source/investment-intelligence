<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MarketFeedSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class MarketOwnerBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();
        return $user;
    }

    public function test_two_analysts_explicit_console_bootstrap_and_owner_isolation(): void
    {
        config(['market_feed.owner_id' => null, 'market_feed.enabled' => false]);
        Http::fake();
        $other = $this->analyst();
        $owner = $this->analyst();
        $settings = app(MarketFeedSettings::class);
        $this->assertFalse($settings->canManage($owner->id));
        $this->artisan('ii:initialize-market-owner', ['--user' => $owner->id])->assertFailed();
        $this->assertDatabaseCount('ii_market_feed_settings', 0);
        $this->artisan('ii:initialize-market-owner', ['--user' => $owner->id, '--confirm' => true])->assertSuccessful();
        $cfg = $settings->current();
        $this->assertSame($owner->id, $cfg['owner_id']);
        $this->assertFalse($cfg['enabled']);
        $this->assertFalse($cfg['discovery_enabled']);
        $this->assertFalse($cfg['storage_permitted']);
        $this->assertSame('LOCAL_CONSOLE_EXPLICIT', $cfg['owner_bootstrap_method']);
        $this->assertSame('analyst', $owner->fresh()->ii_research_role);
        $this->assertSame('analyst', $other->fresh()->ii_research_role);
        $this->assertTrue($settings->canManage($owner->id));
        $this->assertFalse($settings->canManage($other->id));
        $this->actingAs($owner)->get('/ii/market-data')->assertOk()
            ->assertSee('Simpan pengaturan dan nonaktifkan otomatisasi')->assertViewHas('control', fn ($control) => !$control['configured']);
        $this->post('/ii/market-data/credential', ['api_key' => 'synthetic-test-key'])->assertSessionHasErrors('credential');
        $this->post('/ii/market-data/policy', ['mics' => 'XNAS', 'rights_reference' => 'synthetic-only',
            'daily_request_limit' => 10, 'storage_permitted' => '1', 'automation_permitted' => '1'])
            ->assertSessionHas('market_status', 'POLICY_SAVED_DISABLED');
        $this->assertSame($cfg['owner_bootstrapped_at'], $settings->current()['owner_bootstrapped_at']);
        $this->actingAs($other)->post('/ii/market-data/action', ['action' => 'stop', 'confirm' => '1'])->assertForbidden();
        $this->artisan('ii:initialize-market-owner', ['--user' => $other->id, '--confirm' => true])->assertFailed();
        $this->assertSame($owner->id, $settings->current()['owner_id']);
        Http::assertNothingSent();
    }

    public function test_invalid_active_and_locked_bootstrap_are_rejected(): void
    {
        config(['market_feed.owner_id' => null, 'market_feed.enabled' => false]);
        $owner = $this->analyst();
        $reviewer = $this->analyst();
        $reviewer->forceFill(['ii_research_role' => 'reviewer'])->save();
        $this->artisan('ii:initialize-market-owner', ['--user' => $reviewer->id, '--confirm' => true])->assertFailed();
        $this->artisan('ii:initialize-market-owner', ['--user' => 999999, '--confirm' => true])->assertFailed();
        config(['market_feed.enabled' => true]);
        $this->artisan('ii:initialize-market-owner', ['--user' => $owner->id, '--confirm' => true])->assertFailed();
        config(['market_feed.enabled' => false, 'market_feed.owner_id' => $reviewer->id]);
        $this->artisan('ii:initialize-market-owner', ['--user' => $owner->id, '--confirm' => true])->assertFailed();
        config(['market_feed.owner_id' => null]);
        $lock = Cache::lock('ii-market-universe-run', 30);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('ii:initialize-market-owner', ['--user' => $owner->id, '--confirm' => true])->assertFailed();
        } finally {
            $lock->release();
        }
        $this->assertDatabaseCount('ii_market_feed_settings', 0);
    }
}
