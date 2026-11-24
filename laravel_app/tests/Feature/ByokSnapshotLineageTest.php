<?php

namespace Tests\Feature;

use App\Models\IssuerDocument;
use App\Models\IssuerSource;
use App\Models\User;
use App\Services\ByokSnapshotLineage;
use App\Services\FundamentalSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ByokSnapshotLineageTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_vendor_source_suppresses_derived_numbers_and_retains_decision_gates(): void
    {
        $policy = app(ByokSnapshotLineage::class);
        $record = [
            'ticker' => 'AAPL',
            'source_name' => 'Independent report',
            'source_url' => 'https://api.marketdata.app/v1/stocks/quotes/AAPL/',
            'years' => [['year' => 2024, 'period_end' => '2024-12-31',
                'revenue' => 100, 'market_price' => 250, 'net_margin_pct' => 12.5]],
            'valuation_market_price' => 250,
            'net_margin_pct' => 12.5,
            'eps' => 1.25,
            'pe_ratio' => 200,
            'operating_cash_flow' => 50,
            'valuation_status' => 'AVAILABLE_AS_OF_2025-01-02',
            'trading_authorized' => true,
        ];
        $result = $policy->apply($record);
        $this->assertSame('RESTRICTED_BYOK_SOURCE_DETECTED', $result['byok_lineage']);
        $this->assertSame('DENIED', $result['byok_export_clearance']);
        $this->assertNull($result['valuation_market_price']);
        $this->assertNull($result['eps']);
        $this->assertNull($result['pe_ratio']);
        $this->assertNull($result['years'][0]['revenue']);
        $this->assertNull($result['years'][0]['market_price']);
        $this->assertSame(2024, $result['years'][0]['year']);
        $this->assertSame('N/A_BYOK_RESTRICTED_SOURCE', $result['valuation_status']);
        $this->assertSame('NOT_ADMITTED', $result['gate']);
        $this->assertSame('HOLD', $result['decision']);
        $this->assertSame('NONE', $result['trading_authority']);
        $this->assertFalse($result['trading_authorized']);
    }

    public function test_restricted_vendor_is_detected_from_price_url_and_terms_even_with_generic_source_name(): void
    {
        $policy = app(ByokSnapshotLineage::class);
        $this->assertTrue($policy->isRestricted([
            'source_name' => 'Generic EOD data',
            'market_price_source' => 'Example broker',
            'market_price_source_url' => 'https://api.marketdata.app/v1/stocks/quotes/AAPL/',
        ]));
        $this->assertTrue($policy->isRestricted([
            'source_name' => 'Generic financial API',
            'terms_url' => 'https://marketdata.app/terms',
        ]));
        $this->assertFalse($policy->isRestricted([
            'source_name' => 'Independent SEC filing',
            'terms_url' => 'https://example.test/marketdata.app/policy',
        ]));
    }

    public function test_non_marketdata_sources_are_not_falsely_granted_export_rights(): void
    {
        $record = ['ticker' => 'AAPL', 'source_name' => 'Independent filings',
            'source_url' => 'https://www.sec.gov/Archives/edgar/', 'eps' => 8.5];
        $result = app(ByokSnapshotLineage::class)->apply($record);
        $this->assertSame(8.5, $result['eps']);
        $this->assertSame('NO_RESTRICTED_BYOK_SOURCE_IDENTIFIED', $result['byok_lineage']);
        $this->assertSame('NOT_ASSESSED', $result['byok_export_clearance']);
    }

    public function test_legacy_snapshot_writer_fails_before_persisting_restricted_byok_data(): void
    {
        Storage::fake('local');
        $service = app(FundamentalSnapshotService::class);
        $this->assertSame([], Storage::disk('local')->allFiles('ii_fundamental_hub/u17'));
        try {
            $service->save(17, [
                'ticker' => 'AAPL',
                'source_name' => 'Legacy MarketData.app BYOK',
                'source_url' => 'https://api.marketdata.app/v1/stocks/quotes/AAPL/',
                'years' => [['year' => 2024, 'revenue' => 100]],
            ]);
            $this->fail('Restricted source must not reach raw snapshot storage.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('II_BYOK_RESTRICTED_SNAPSHOT_STORAGE_DENIED', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('ii_fundamental_hub/u17'));

        $service->save(17, [
            'ticker' => 'AAPL',
            'source_name' => 'Independent research fixture',
            'source_url' => 'https://example.test/report',
            'years' => [['year' => 2024, 'revenue' => 100]],
        ]);
        $this->assertCount(1, Storage::disk('local')->allFiles('ii_fundamental_hub/u17'));
    }

    public function test_snapshot_boundary_quarantines_old_issuer_document_before_screener_calculation(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'admin'])->save();
        $csv = "fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of\n".
            "2024,2024-12-31,false,100,10,500,300,250,50,1000,100,20,40,,\n";
        $this->actingAs($owner)->post('/ii/fundamentals/import', [
            'ticker' => 'AAPL', 'issuer_name' => 'Issuer fixture',
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
        $clean = app(FundamentalSnapshotService::class)->latestByTicker($owner->id)['AAPL'];
        $this->assertSame('NOT_ASSESSED', $clean['byok_export_clearance']);
        $this->assertNotNull($clean['years'][0]['revenue']);
        IssuerSource::query()->whereKey($document->source_id)
            ->update(['name' => 'Legacy MarketData.app BYOK']);

        $tainted = app(FundamentalSnapshotService::class)->latestByTicker($owner->id)['AAPL'];
        $this->assertSame('DENIED', $tainted['byok_export_clearance']);
        $this->assertNull($tainted['years'][0]['revenue']);
        $this->assertNull($tainted['net_margin_pct']);
        $this->assertNull($tainted['eps']);
        $this->assertSame('N/A_BYOK_RESTRICTED_SOURCE', $tainted['valuation_status']);
        $this->assertSame('NOT_ADMITTED', $tainted['gate']);
    }
}
