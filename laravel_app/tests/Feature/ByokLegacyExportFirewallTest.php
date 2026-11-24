<?php

namespace Tests\Feature;

use App\Models\IssuerMarketPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ByokLegacyExportFirewallTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();
        return $user;
    }

    public function test_existing_restricted_marketdata_row_blocks_all_issuer_and_screener_csv_routes(): void
    {
        $owner = $this->owner();
        IssuerMarketPrice::create([
            'user_id' => $owner->id,
            'issuer_code' => 'AAPL',
            'observed_on' => '2025-01-03',
            'currency' => 'USD',
            'price' => '200',
            'unit' => 'per_share',
            'adjustment_status' => 'UNADJUSTED_CLOSE',
            'source_name' => 'Legacy MarketData.app BYOK',
            'source_url' => 'https://api.marketdata.app/v1/stocks/quotes/AAPL/',
            'license_status' => 'licensed',
            'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'LICENSED_PROVIDER',
            'provenance_confidence' => 'HIGH',
            'redistribution_allowed' => false,
            'sha256' => hash('sha256', 'restricted-legacy-row'),
            'imported_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get('/ii/fundamentals/coverage/export.csv')->assertForbidden();
        $this->get('/ii/fundamentals/AAPL/export.csv')->assertForbidden();
        $this->get('/ii/screener/export.csv')->assertForbidden();
        $this->assertDatabaseCount('ii_issuer_market_prices', 1);

        // No global market-data pooling: another user's unrelated research
        // must not be blocked by the first user's restricted record.
        $other = $this->owner();
        $this->actingAs($other)
            ->get('/ii/fundamentals/coverage/export.csv')
            ->assertStatus(200);
    }
}
