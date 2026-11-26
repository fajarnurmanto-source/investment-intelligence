<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LocalByokCredentialVault;
use App\Services\MarketAcquisitionLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MarketAcquisitionLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_vault_is_owner_and_provider_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $vault = app(LocalByokCredentialVault::class);
        $vault->save($owner->id, 'twelve_data', 'fixture_key_not_real');
        $this->assertTrue($vault->connected($owner->id, 'twelve_data'));
        $this->assertFalse($vault->connected($other->id, 'twelve_data'));
        $this->assertFalse($vault->connected($owner->id, 'marketdata'));
        $this->assertSame('fixture_key_not_real', $vault->key($owner->id, 'twelve_data'));
        $this->assertStringNotContainsString('fixture_key_not_real', DB::table('ii_byok_credentials')->value('encrypted_api_key'));
    }

    public function test_daily_request_budget_is_persistent_and_conservative(): void
    {
        $ledger = app(MarketAcquisitionLedger::class);
        $ledger->reserve(['daily_request_limit' => 1]);
        try {
            $ledger->reserve(['daily_request_limit' => 1]);
            $this->fail('Budget must stop additional attempts');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('II_PROVIDER_DAILY_BUDGET_EXHAUSTED', $exception->getMessage());
        }
        $this->assertSame(1, (int) DB::table('ii_market_request_days')->value('attempts'));
    }

    public function test_missing_vault_key_blocks_network_and_records_failure(): void
    {
        $owner = User::factory()->create();
        Http::fake();
        config(['market_feed' => [
            'enabled' => true, 'provider' => 'twelve_data', 'owner_id' => $owner->id,
            'ticker' => 'AAPL', 'provider_symbol' => 'AAPL', 'provider_mic' => 'XNAS',
            'api_key' => 'ignored_plaintext_fixture',
            'source_name' => 'Twelve Data', 'source_url' => 'https://api.twelvedata.com/time_series',
            'rights_reference' => 'TEST_ONLY', 'storage_permitted' => true, 'automation_permitted' => true,
            'currency' => 'USD', 'price_adjustment' => 'unadjusted', 'session_timezone' => 'America/New_York',
        ]]);
        $this->artisan('ii:sync-market-feed')->expectsOutput('II_PROVIDER_CREDENTIAL_UNAVAILABLE')->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ii_market_request_days', 0);
        $this->assertDatabaseHas('ii_market_acquisition_receipts', [
            'user_id' => $owner->id, 'ticker' => 'AAPL', 'status' => 'II_PROVIDER_CREDENTIAL_UNAVAILABLE',
        ]);
    }

    public function test_receipt_sanitizes_unexpected_failure(): void
    {
        $owner = User::factory()->create();
        try {
            app(MarketAcquisitionLedger::class)->run(
                ['owner_id' => $owner->id, 'ticker' => 'AAPL', 'provider' => 'twelve_data'],
                fn () => throw new \RuntimeException('secret must never appear in receipt')
            );
            $this->fail('Exception must propagate');
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('ii_market_acquisition_receipts', ['status' => 'FAILED']);
            $this->assertNotNull(DB::table('ii_market_acquisition_receipts')->value('completed_at'));
        }
    }
}
