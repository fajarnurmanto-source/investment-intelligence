<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ByokIntakeFirewall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ByokDataBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'admin'])->save();
        return $user;
    }

    private function source(array $overrides = []): array
    {
        return array_merge([
            'source_name' => 'Independent EOD source',
            'publisher' => 'Independent author',
            'source_url' => 'https://example.test/prices',
            'license_status' => 'licensed',
            'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'licensed_provider',
            'provenance_confidence' => 'high',
            'rights_confirmed' => '1',
        ], $overrides);
    }

    public function test_known_byok_provider_identity_is_rejected_by_all_source_field_variants(): void
    {
        $firewall = app(ByokIntakeFirewall::class);
        foreach ([
            ['source_name' => 'MarketData.app BYOK'],
            ['source_name' => 'MarketData BYOK delayed quote'],
            ['publisher' => 'MarketData.app'],
            ['source_url' => 'https://api.marketdata.app/v1/stocks/quotes/AAPL/'],
            ['terms_url' => 'https://marketdata.app/terms'],
            ['source_url' => 'https://sub.api.marketdata.app/data'],
        ] as $variant) {
            try {
                $firewall->assertIndependentSource($this->source($variant));
                $this->fail('Restricted source was accepted: '.json_encode($variant));
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('II_BYOK_RESTRICTED_SOURCE_CANNOT_BE_IMPORTED', $e->getMessage());
            }
        }
        // Do not break independent research or source-code Git synchronization.
        $firewall->assertIndependentSource($this->source());
    }

    public function test_manual_market_price_from_byok_cannot_enter_exportable_price_table(): void
    {
        $this->actingAs($this->owner())
            ->post('/ii/fundamentals/market-prices', array_merge(
                $this->source(['source_url' => 'https://api.marketdata.app/v1/stocks/quotes/AAPL/']),
                [
                    'ticker' => 'AAPL', 'observed_on' => '2025-01-02',
                    'currency' => 'USD', 'price' => '123.45',
                    'unit' => 'per_share', 'adjustment_status' => 'unadjusted_close',
                ]
            ))->assertRedirect()->assertSessionHasErrors();
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
    }

    public function test_batch_byok_source_is_rejected_before_persisting_uploaded_csv(): void
    {
        Storage::fake('local');
        $csv = "ticker,observed_on,currency,price,adjustment_status\n".
            "AAPL,2025-01-02,USD,123.45,unadjusted_close\n";
        $this->actingAs($this->owner())
            ->post('/ii/fundamentals/market-prices/batch', array_merge(
                $this->source(['publisher' => 'MarketData.app']),
                ['market_prices_csv' => UploadedFile::fake()->createWithContent('marketdata.csv', $csv)]
            ))->assertRedirect()->assertSessionHasErrors();
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('ii_price_batches'));
    }
    public function test_restricted_byok_provider_cannot_be_registered_as_fundamental_document(): void
    {
        Storage::fake('local');
        $csv = "fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of\n".
            "2024,2024-12-31,false,100,10,500,300,250,50,1000,100,20,40,,\n";
        $this->actingAs($this->owner())
            ->post('/ii/fundamentals/import', [
                'ticker' => 'AAPL', 'issuer_name' => 'Synthetic issuer',
                'sector' => 'Technology', 'kind' => 'user_supplied',
                'report_period' => 'FY2024', 'fiscal_period_type' => 'annual',
                'report_date' => '2025-03-01', 'currency' => 'USD',
                'multiplier' => '1', 'consolidated' => '1',
                'source_name' => 'MarketData.app BYOK',
                'publisher' => 'MarketData.app',
                'source_url' => 'https://api.marketdata.app/v1/stocks/quotes/AAPL/',
                'license_status' => 'licensed',
                'usage_rights' => 'licensed_internal',
                'source_authenticity_status' => 'licensed_provider',
                'numeric_reconciliation_status' => 'unreconciled',
                'provenance_confidence' => 'low',
                'rights_confirmed' => '1',
                'fundamental_file' => UploadedFile::fake()->createWithContent('fundamentals.csv', $csv),
            ])->assertRedirect()->assertSessionHasErrors();
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
    }

}
