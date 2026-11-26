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
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->post('/ii/market-data/action', ['action' => 'stop', 'confirm' => '1'])->assertForbidden();
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
