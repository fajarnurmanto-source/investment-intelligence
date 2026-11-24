<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ByokRightsPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class LocalByokArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'admin'])->save();
        return $user;
    }

    public function test_byok_credentials_are_local_encrypted_and_never_returned_by_ui(): void
    {
        $owner = $this->owner();
        $secret = 'byok-test-unique-secret-2026';
        $this->actingAs($owner)->post('/ii/data-sources/connect', [
            'provider' => 'marketdata',
            'api_key' => $secret,
            'confirm_private_subscription' => '1',
        ])->assertRedirect('/ii/data-sources');
        $row = DB::table('ii_byok_credentials')->where('user_id', $owner->id)->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString($secret, $row->encrypted_api_key);
        $this->assertSame($secret, Crypt::decryptString($row->encrypted_api_key));
        $this->get('/ii/data-sources')->assertOk()->assertDontSee($secret)
            ->assertSee('Kunci tersedia di perangkat');
        $this->post('/ii/data-sources/disconnect')->assertRedirect('/ii/data-sources');
        $this->assertDatabaseCount('ii_byok_credentials', 0);
    }

    public function test_local_only_credential_endpoints_reject_nonlocal_ip(): void
    {
        $this->actingAs($this->owner())
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->get('/ii/data-sources')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->post('/ii/data-sources/connect', [
                'provider' => 'marketdata',
                'api_key' => 'secret',
                'confirm_private_subscription' => '1',
            ])->assertForbidden();
        $this->assertDatabaseCount('ii_byok_credentials', 0);
    }

    public function test_quote_fetches_once_from_user_device_and_exposes_no_export_or_raw_archive(): void
    {
        $this->actingAs($this->owner())->post('/ii/data-sources/connect', [
            'provider' => 'marketdata',
            'api_key' => 'test-owner-key',
            'confirm_private_subscription' => '1',
        ])->assertRedirect('/ii/data-sources');

        Http::preventStrayRequests();
        Http::fake(['https://api.marketdata.app/v1/stocks/quotes/AAPL/' =>
            Http::response([
                's' => 'ok', 'symbol' => ['AAPL'], 'bid' => [209.10],
                'ask' => [209.20], 'last' => [209.15], 'updated' => [1790000000],
            ], 200, ['Content-Type' => 'application/json']),
        ]);
        $this->post('/ii/data-sources/quote', ['symbol' => 'AAPL'])
            ->assertOk()->assertSee('DELAYED_QUOTES_ENDPOINT')
            ->assertSee('NOT_ADMITTED')->assertDontSee('test-owner-key')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) =>
            $request->method() === 'GET'
            && $request->url() === 'https://api.marketdata.app/v1/stocks/quotes/AAPL/'
            && $request->hasHeader('Authorization', 'Bearer test-owner-key'));
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
        $this->assertFalse((new ByokRightsPolicy)->allows('marketdata', 'export'));
        $this->assertFalse((new ByokRightsPolicy)->allows('marketdata', 'cloud_llm'));
        $this->assertFalse((new ByokRightsPolicy)->allows('marketdata', 'raw_archive'));
        $this->assertFalse((new ByokRightsPolicy)->allows('marketdata', 'sync'));
    }

    public function test_no_key_and_invalid_provider_fail_closed_without_network(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs($this->owner())
            ->post('/ii/data-sources/quote', ['symbol' => 'AAPL'])
            ->assertRedirect()->assertSessionHasErrors('symbol');
        $this->post('/ii/data-sources/connect', [
            'provider' => 'other', 'api_key' => 'SENSITIVE_API_KEY_NEVER_FLASH',
            'confirm_private_subscription' => '1',
        ])->assertSessionHasErrors('provider');
        $this->assertStringNotContainsString('SENSITIVE_API_KEY_NEVER_FLASH', json_encode(session()->all()));
        $this->assertDatabaseCount('ii_byok_credentials', 0);
        Http::assertNothingSent();
    }
}
