<?php

namespace Tests\Feature;

use App\Models\User;
use App\Jobs\SyncMarketUniverse;
use App\Services\MarketFeedSettings;
use App\Services\LocalByokCredentialVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MarketControlCenterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'admin'])->save();
        return $user;
    }

    private function policy(): array
    {
        return ['mics' => 'XNAS', 'rights_reference' => 'synthetic-test-license',
            'daily_request_limit' => 10, 'storage_permitted' => '1', 'automation_permitted' => '1'];
    }

    public function test_forms_owner_isolation_validation_and_revocation(): void
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        Http::preventStrayRequests();
        Storage::fake('local');
        $owner = $this->admin();
        $other = $this->admin();
        $this->actingAs($owner)->get('/ii/market-data')->assertOk()
            ->assertSee('Autonomous Data Control Center')->assertSee('Otorisasi discovery');
        $this->post('/ii/market-data/policy', array_replace($this->policy(), ['storage_permitted' => '0']))
            ->assertSessionHasErrors('storage_permitted');
        $this->assertDatabaseCount('ii_market_feed_settings', 0);
        $this->post('/ii/market-data/policy', $this->policy())->assertSessionHas('market_status', 'POLICY_SAVED_DISABLED');
        $this->assertFalse(app(MarketFeedSettings::class)->current()['enabled']);
        $this->actingAs($other)->post('/ii/market-data/policy', $this->policy())->assertForbidden();
        $this->post('/ii/market-data/credential', ['api_key' => 'synthetic-test-key'])->assertForbidden();
        $this->post('/ii/market-data/action', ['action' => 'stop', 'confirm' => '1'])->assertForbidden();
        $this->get('/ii/market-data')->assertOk()->assertDontSee('synthetic-test-license');
        $this->actingAs($owner)->post('/ii/market-data/credential', ['api_key' => 'bad secret'])
            ->assertSessionHasErrors('credential');
        $this->assertNull(session()->getOldInput('api_key'));
        $this->post('/ii/market-data/action', ['action' => 'discovery', 'confirm' => '1'])
            ->assertSessionHasErrors('operation');
        $this->post('/ii/market-data/action', ['action' => 'activate', 'confirm' => '1'])
            ->assertSessionHasErrors('operation');
        $this->post('/ii/market-data/action', ['action' => 'stop', 'confirm' => '1'])
            ->assertSessionHas('market_status', 'STOP_ACCEPTED');
        app()->call([new SyncMarketUniverse($owner->id, 'AAA'), 'handle']);
        $this->assertDatabaseCount('ii_market_acquisition_receipts', 0);
        Http::assertNothingSent();

    }

    public function test_nonlocal_post_is_forbidden_independently_of_throttle(): void
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        $owner = $this->admin();
        $this->actingAs($owner)->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->post('/ii/market-data/action', ['action' => 'stop', 'confirm' => '1'])->assertForbidden();
    }

    public function test_synthetic_credential_discovery_activation_and_stop(): void
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        Storage::fake('local');
        Http::preventStrayRequests();
        $owner = $this->admin();
        $this->actingAs($owner)->post('/ii/market-data/policy', $this->policy())
            ->assertSessionHas('market_status', 'POLICY_SAVED_DISABLED');
        $this->post('/ii/market-data/credential', ['api_key' => 'synthetic-test-key'])
            ->assertSessionHas('market_status', 'KEY_STORED_DISABLED');
        $this->assertTrue(app(LocalByokCredentialVault::class)->connected($owner->id, 'twelve_data'));
        $this->assertSame('synthetic-test-key', app(LocalByokCredentialVault::class)->key($owner->id, 'twelve_data'));
        $this->get('/ii/market-data')->assertOk()->assertDontSee('synthetic-test-key');
        $this->post('/ii/market-data/action', ['action' => 'discovery', 'confirm' => '1'])
            ->assertSessionHas('market_status', 'DISCOVERY_ACCEPTED');
        $this->assertTrue(app(MarketFeedSettings::class)->current()['discovery_enabled']);
        // Exercise the actual workflow with a synthetic provider response.
        Http::fake([
            'https://api.twelvedata.com/exchanges*' => Http::response(['status' => 'ok', 'data' => [
                ['mic_code' => 'XNAS', 'timezone' => 'America/New_York'],
            ]]),
            'https://api.twelvedata.com/stocks*' => Http::response(['status' => 'ok', 'data' => [
                ['mic_code' => 'XNAS', 'symbol' => 'AAA', 'currency' => 'USD', 'type' => 'Common Stock'],
            ]]),
        ]);
        $cfg = app(MarketFeedSettings::class)->current();
        $result = app(\App\Services\MarketDiscoveryWorkflow::class)->run($owner->id, $cfg, $cfg['mics']);
        $this->assertSame(['AAA'], array_column($result['proposal']['entries'], 'ticker'));
        $cached = app(\App\Services\MarketDiscoveryWorkflow::class)->run($owner->id, app(MarketFeedSettings::class)->current(), ['XNAS']);
        $this->assertSame(['XNAS'], $cached['reused_mics']);
        Http::assertSentCount(2);
        $this->post('/ii/market-data/action', ['action' => 'activate', 'confirm' => '1'])
            ->assertSessionHas('market_status', 'ACTIVATE_ACCEPTED');
        $this->assertTrue(app(MarketFeedSettings::class)->current()['enabled']);
        $old = new SyncMarketUniverse($owner->id, 'AAA', app(MarketFeedSettings::class)->current()['authorization_generation']);
        $this->post('/ii/market-data/action', ['action' => 'stop', 'confirm' => '1'])
            ->assertSessionHas('market_status', 'STOP_ACCEPTED');
        $cfg = app(MarketFeedSettings::class)->current();
        $this->assertFalse($cfg['enabled']);
        $this->assertFalse($cfg['discovery_enabled']);
        app()->call([new SyncMarketUniverse($owner->id, 'AAA'), 'handle']);
        app(MarketFeedSettings::class)->setEnabled($owner->id, true);
        $this->assertNotSame($old->authorizationGeneration, app(MarketFeedSettings::class)->current()['authorization_generation']);
        app()->call([$old, 'handle']);
        app()->call([new SyncMarketUniverse($owner->id, 'AAA'), 'handle']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ii_market_acquisition_receipts', 0);
    }

    public function test_reviewer_cannot_manage_even_when_owner(): void
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        $owner = $this->admin();
        $this->actingAs($owner)->post('/ii/market-data/policy', $this->policy())->assertRedirect();
        $owner->forceFill(['ii_research_role' => 'reviewer'])->save();
        $this->actingAs($owner)->post('/ii/market-data/action', ['action' => 'stop', 'confirm' => '1'])->assertForbidden();
        $this->get('/ii/market-data')->assertOk()->assertDontSee('Simpan kebijakan (nonaktif)');
    }

    public function test_cli_disconnect_revokes_persisted_authorization(): void
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        $owner = $this->admin();
        $this->actingAs($owner)->post('/ii/market-data/policy', $this->policy())->assertRedirect();
        $settings = app(MarketFeedSettings::class);
        $settings->replaceCredential($owner->id, 'synthetic-test-key');
        $settings->authorizeDiscovery($owner->id);
        $before = $settings->current()['authorization_generation'];
        $this->artisan('ii:connect-market-feed', ['--disconnect' => true])->assertSuccessful();
        $this->assertFalse($settings->current()['enabled']);
        $this->assertFalse($settings->current()['discovery_enabled']);
        $this->assertNotSame($before, $settings->current()['authorization_generation']);
        $this->assertFalse(app(LocalByokCredentialVault::class)->connected($owner->id, 'twelve_data'));
    }

    public function test_credential_storage_failure_revokes_old_key_and_blocks_reauthorization(): void
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        Http::fake();
        $owner = $this->admin();
        $this->actingAs($owner)->post('/ii/market-data/policy', $this->policy())->assertRedirect();
        $settings = app(MarketFeedSettings::class);
        $settings->replaceCredential($owner->id, 'synthetic-old-key');
        $settings->authorizeDiscovery($owner->id);
        $before = $settings->current()['authorization_generation'];
        DB::unprepared("CREATE TRIGGER ii_test_reject_credential BEFORE INSERT ON ii_byok_credentials BEGIN SELECT RAISE(ABORT, 'synthetic storage failure'); END");
        try {
            $this->post('/ii/market-data/credential', ['api_key' => 'synthetic-new-key'])
                ->assertSessionHasErrors('credential');
        } finally {
            DB::unprepared('DROP TRIGGER ii_test_reject_credential');
        }
        $cfg = $settings->current();
        $this->assertFalse($cfg['enabled']);
        $this->assertFalse($cfg['discovery_enabled']);
        $this->assertTrue($cfg['credential_replacement_pending']);
        $this->assertSame('[]', $cfg['universe_json']);
        $this->assertNotSame($before, $cfg['authorization_generation']);
        $this->assertFalse(app(LocalByokCredentialVault::class)->connected($owner->id, 'twelve_data'));
        $this->assertNull(session()->getOldInput('api_key'));
        $this->post('/ii/market-data/action', ['action' => 'discovery', 'confirm' => '1'])
            ->assertSessionHasErrors('operation');
        $this->assertFalse($settings->current()['discovery_enabled']);
        Http::assertNothingSent();
    }

    public function test_shared_lock_contention_leaves_policy_and_key_unchanged(): void
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        Http::fake();
        $owner = $this->admin();
        $this->actingAs($owner)->post('/ii/market-data/policy', $this->policy())->assertRedirect();
        $settings = app(MarketFeedSettings::class);
        $settings->replaceCredential($owner->id, 'synthetic-old-key');
        $before = $settings->current();
        $lock = \Illuminate\Support\Facades\Cache::lock('ii-market-universe-run', 30);
        $this->assertTrue($lock->get());
        try {
            $this->post('/ii/market-data/credential', ['api_key' => 'synthetic-new-key'])
                ->assertSessionHasErrors('credential');
            $this->assertSame($before, $settings->current());
            $this->assertSame('synthetic-old-key', app(LocalByokCredentialVault::class)->key($owner->id, 'twelve_data'));
        } finally {
            $lock->release();
        }
        $settings->replaceCredential($owner->id, 'synthetic-new-key');
        $this->assertNotSame($before['authorization_generation'], $settings->current()['authorization_generation']);
        Http::assertNothingSent();
    }

    public function test_runtime_role_revocation_stops_queue_and_discovery_and_requires_reactivation(): void
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        Http::fake();
        $owner = $this->admin();
        $this->actingAs($owner)->post('/ii/market-data/policy', $this->policy())->assertRedirect();
        $settings = app(MarketFeedSettings::class);
        $settings->replaceCredential($owner->id, 'synthetic-test-key');
        $settings->authorizeDiscovery($owner->id);
        $row = DB::table('ii_market_feed_settings')->where('id', 1)->first();
        $stored = json_decode($row->configuration, true, 16, JSON_THROW_ON_ERROR);
        $stored['universe_json'] = json_encode([['ticker' => 'AAA', 'provider_symbol' => 'AAA',
            'provider_mic' => 'XNAS', 'currency' => 'USD', 'price_adjustment' => 'unadjusted',
            'session_timezone' => 'America/New_York']], JSON_THROW_ON_ERROR);
        DB::table('ii_market_feed_settings')->where('id', 1)->update(['configuration' => json_encode($stored, JSON_THROW_ON_ERROR)]);
        $settings->setEnabled($owner->id, true);
        $before = $settings->current();
        $job = new SyncMarketUniverse($owner->id, 'AAA', $before['authorization_generation']);
        $owner->forceFill(['ii_research_role' => 'reviewer'])->save();
        app()->call([$job, 'handle']);
        $this->assertFalse($settings->current()['enabled']);
        $this->assertFalse($settings->current()['discovery_enabled']);
        $this->assertNotSame($before['authorization_generation'], $settings->current()['authorization_generation']);
        try {
            app(\App\Services\MarketDiscoveryWorkflow::class)->run($owner->id, $before, $before['mics']);
            $this->fail('Discovery must reject revoked role');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('II_DISCOVERY_AUTHORIZATION_CHANGED', $e->getMessage());
        }
        $owner->forceFill(['ii_research_role' => 'admin'])->save();
        $this->assertFalse($settings->current()['enabled']);
        app()->call([$job, 'handle']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ii_market_acquisition_receipts', 0);
    }

    public function test_discovery_staggers_mics_and_reserves_daily_price_budget(): void
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        Storage::fake('local');
        $owner = $this->admin();
        $this->actingAs($owner)->post('/ii/market-data/policy', array_replace($this->policy(),
            ['mics' => 'XNAS,XNYS', 'daily_request_limit' => 3]))->assertRedirect();
        $settings = app(MarketFeedSettings::class);
        $settings->replaceCredential($owner->id, 'synthetic-test-key');
        $settings->authorizeDiscovery($owner->id);
        Http::fake([
            'https://api.twelvedata.com/exchanges*' => Http::response(['status' => 'ok', 'data' => [
                ['mic_code' => 'XNAS', 'timezone' => 'America/New_York'],
            ]]),
            'https://api.twelvedata.com/stocks*' => Http::response(['status' => 'ok', 'data' => [
                ['mic_code' => 'XNAS', 'symbol' => 'AAA', 'currency' => 'USD', 'type' => 'Common Stock'],
            ]]),
        ]);
        $cfg = $settings->current();
        $result = app(\App\Services\MarketDiscoveryWorkflow::class)->run($owner->id, $cfg, $cfg['mics']);
        $this->assertSame(['XNYS' => 'BUDGET_DEFERRED'], $result['deferred_mics']);
        $this->assertSame(['AAA'], array_column($result['proposal']['entries'], 'ticker'));
        $this->assertSame(1, $result['proposal']['remaining_local_requests']);
        Http::assertSentCount(2);
        $this->assertFalse($settings->current()['enabled']);
    }

    public function test_local_quota_is_rendered_without_claiming_provider_entitlement(): void
    {
        config(['market_feed.owner_id' => 0, 'market_feed.enabled' => false]);
        $owner = $this->admin();
        $this->actingAs($owner)->post('/ii/market-data/policy', $this->policy())->assertRedirect();
        DB::table('ii_market_request_days')->insert(['provider' => 'twelve_data',
            'utc_day' => now('UTC')->toDateString(), 'attempts' => 3]);
        $this->get('/ii/market-data')->assertOk()->assertSee('Sisa anggaran permintaan lokal')
            ->assertSee('BELUM TEROBSERVASI / KEDALUWARSA')->assertViewHas('control',
                fn ($control) => $control['daily_used'] === 3 && $control['provider_credits'] === null);
    }
}
