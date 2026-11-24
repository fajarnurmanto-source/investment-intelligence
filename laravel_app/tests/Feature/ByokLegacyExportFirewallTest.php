<?php

namespace Tests\Feature;

use App\Models\IssuerMarketPrice;
use App\Models\IssuerDocument;
use App\Models\IssuerSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
    public function test_legacy_restricted_fundamental_source_blocks_exports_without_price_rows(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $csv = "fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of\n"
            ."2024,2024-12-31,false,100,10,500,300,250,50,1000,100,20,40,,\n";

        // Import a valid independent fixture then simulate a pre-cutover
        // legacy source record that has subsequently been identified as BYOK.
        $this->actingAs($owner)->post('/ii/fundamentals/import', [
            'ticker' => 'AAPL', 'issuer_name' => 'Fixture',
            'sector' => 'Technology', 'kind' => 'user_supplied',
            'report_period' => 'FY2024', 'fiscal_period_type' => 'annual',
            'report_date' => '2025-03-01', 'currency' => 'USD',
            'multiplier' => '1', 'consolidated' => '1',
            'source_name' => 'Independent filings', 'publisher' => 'Fixture',
            'source_url' => 'https://example.test/filing',
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'licensed_provider',
            'numeric_reconciliation_status' => 'unreconciled',
            'provenance_confidence' => 'low', 'rights_confirmed' => '1',
            'fundamental_file' => UploadedFile::fake()->createWithContent('issuer.csv', $csv),
        ])->assertRedirect('/ii/fundamentals')->assertSessionHasNoErrors();
        $document = IssuerDocument::query()->where('user_id', $owner->id)->firstOrFail();
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
        IssuerSource::query()->whereKey($document->source_id)
            ->update(['name' => 'Legacy MarketData.app BYOK',
                'source_url' => 'https://api.marketdata.app/v1/stocks/quotes/AAPL/']);

        $this->get('/ii/fundamentals/coverage/export.csv')->assertForbidden();
        $this->get('/ii/fundamentals/AAPL/export.csv')->assertForbidden();
        $this->get('/ii/screener/export.csv')->assertForbidden();

        // Unrelated owner does not inherit another user's vendor restriction.
        $other = $this->owner();
        $this->actingAs($other)
            ->get('/ii/fundamentals/coverage/export.csv')->assertOk();
    }

}
