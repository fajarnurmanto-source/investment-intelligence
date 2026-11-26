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
        $this->post('/ii/market-data/action', ['action' => 'activate', 'confirm' => '1'])
            ->assertSessionHas('market_status', 'ACTIVATE_ACCEPTED');
        $this->assertTrue(app(MarketFeedSettings::class)->current()['enabled']);
        $this->post('/ii/market-data/action', ['action' => 'stop', 'confirm' => '1'])
            ->assertSessionHas('market_status', 'STOP_ACCEPTED');
        $cfg = app(MarketFeedSettings::class)->current();
        $this->assertFalse($cfg['enabled']);
        $this->assertFalse($cfg['discovery_enabled']);
        app()->call([new SyncMarketUniverse($owner->id, 'AAA'), 'handle']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ii_market_acquisition_receipts', 0);
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
