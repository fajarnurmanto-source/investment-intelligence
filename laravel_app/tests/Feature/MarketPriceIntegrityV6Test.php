<?php

namespace Tests\Feature;

use App\Models\IssuerMarketPrice;
use App\Models\User;
use App\Services\FundamentalSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketPriceIntegrityV6Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    private function issuer(User $user): void
    {
        $csv = "fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of\n"
            ."2023,2023-12-31,false,100,10,500,300,250,50,1000,100,20,40,,\n"
            ."2024,2024-12-31,false,120,12,550,320,270,60,1000,110,25,45,,\n";

        $this->actingAs($user)->post('/ii/fundamentals/import', [
            'ticker' => 'CONFLICT.JK', 'issuer_name' => 'Market Integrity Fixture',
            'sector' => 'Industrials', 'kind' => 'user_supplied', 'report_period' => 'FY2024',
            'fiscal_period_type' => 'annual', 'report_date' => '2025-03-01', 'currency' => 'IDR',
            'multiplier' => '1', 'consolidated' => '1', 'source_name' => 'Synthetic issuer report',
            'publisher' => 'Example', 'source_url' => 'https://example.test/report',
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'licensed_provider',
            'numeric_reconciliation_status' => 'verified_against_source',
            'provenance_confidence' => 'high', 'rights_confirmed' => '1',
            'fundamental_file' => UploadedFile::fake()->createWithContent('conflict.csv', $csv),
        ])->assertRedirect('/ii/fundamentals');
    }

    private function price(User $user, string $value, string $source = 'Licensed source', string $date = '2025-01-31', string $adjustment = 'unadjusted_close'): void
    {
        $this->actingAs($user)->post('/ii/fundamentals/market-prices', [
            'ticker' => 'CONFLICT.JK', 'observed_on' => $date, 'currency' => 'IDR',
            'price' => $value, 'unit' => 'per_share', 'adjustment_status' => $adjustment,
            'source_name' => $source, 'publisher' => 'Test Vendor',
            'source_url' => 'https://example.test/prices/'.rawurlencode($source),
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'licensed_provider',
            'provenance_confidence' => 'high', 'rights_confirmed' => '1',
        ])->assertRedirect('/ii/fundamentals')->assertSessionHasNoErrors();
    }

    public function test_conflicting_latest_date_prices_are_held_with_no_valuation(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = $this->analyst();
        $other = $this->analyst();
        $this->issuer($owner);
        $this->price($owner, '100', 'First licensed source');

        $baseline = app(FundamentalSnapshotService::class)->latestByTicker((int) $owner->id)['CONFLICT.JK'];
        $this->assertSame('N/A_SHARE_BASIS_REVIEW_MISSING', $baseline['valuation_status']);

        $this->price($owner, '100.000000', 'Second matching source');
        $matched = app(FundamentalSnapshotService::class)->latestByTicker((int) $owner->id)['CONFLICT.JK'];
        $this->assertSame('N/A_SHARE_BASIS_REVIEW_MISSING', $matched['valuation_status']);

        $this->price($other, '999', 'Another user vendor');
        $stillMatched = app(FundamentalSnapshotService::class)->latestByTicker((int) $owner->id)['CONFLICT.JK'];
        $this->assertSame('N/A_SHARE_BASIS_REVIEW_MISSING', $stillMatched['valuation_status']);

        $this->price($owner, '101', 'Conflicting vendor');
        $conflicted = app(FundamentalSnapshotService::class)->latestByTicker((int) $owner->id)['CONFLICT.JK'];
        $this->assertSame('N/A_MARKET_PRICE_CONFLICT', $conflicted['valuation_status']);
        $this->assertNull($conflicted['pe_ratio']);
        $this->assertNull($conflicted['pbv_ratio']);
        $this->assertNull($conflicted['ev_ebitda_ratio']);
        $this->assertSame('HOLD', $conflicted['decision']);
        $this->assertSame('NONE', $conflicted['trading_authority']);
        $this->assertSame(3, IssuerMarketPrice::where('user_id', $owner->id)->count());
        $this->actingAs($owner)->get('/ii/fundamentals')
            ->assertOk()->assertSee('N/A_MARKET_PRICE_CONFLICT');
    }

    public function test_manual_future_price_is_rejected_and_stored_future_observation_is_held(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->analyst();
        $this->issuer($user);

        $tomorrow = now()->addDay()->toDateString();
        $this->actingAs($user)->post('/ii/fundamentals/market-prices', [
            'ticker' => 'CONFLICT.JK', 'observed_on' => $tomorrow, 'currency' => 'IDR',
            'price' => '100', 'unit' => 'per_share', 'adjustment_status' => 'unadjusted_close',
            'source_name' => 'Future test', 'source_url' => 'https://example.test/future',
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'licensed_provider',
            'provenance_confidence' => 'high', 'rights_confirmed' => '1',
        ])->assertSessionHasErrors('observed_on');
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);

        // Defensive handling of legacy/imported rows outside the form validation gate.
        IssuerMarketPrice::create([
            'user_id' => $user->id, 'issuer_code' => 'CONFLICT.JK', 'observed_on' => $tomorrow,
            'currency' => 'IDR', 'price' => '100', 'unit' => 'per_share',
            'adjustment_status' => 'UNADJUSTED_CLOSE', 'source_name' => 'Legacy future row',
            'source_url' => 'https://example.test/legacy',
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'LICENSED_PROVIDER', 'provenance_confidence' => 'HIGH',
            'redistribution_allowed' => false, 'sha256' => hash('sha256', 'future fixture'), 'imported_at' => now(),
        ]);

        $snapshot = app(FundamentalSnapshotService::class)->latestByTicker((int) $user->id)['CONFLICT.JK'];
        $this->assertSame('N/A_FUTURE_MARKET_PRICE_DATE', $snapshot['valuation_status']);
        $this->assertNull($snapshot['pe_ratio']);
        $this->assertSame('NOT_ADMITTED', $snapshot['gate']);
    }
}
