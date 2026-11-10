<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IssuerFundamentalIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICIAL_REPORT = 'https://www.telkom.co.id/minio/show/data/lampiran/1745244391951_original_Annual%20Report%20Telkom%202024%20%281%29.pdf';

    public function test_official_telkom_disclosure_flows_through_provenance_normalization_screener_and_export(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();
        $csv = <<<'CSV'
fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price
2023,2023-12-31,false,149216,24560,287042,135744,60581,99062216600,47699,29007,77579,
2024,2024-12-31,false,149967,23649,299675,142094,61600,99062216600,52909,33905,75579,
CSV;
        $payload = [
            'ticker' => 'TLKM.JK', 'issuer_name' => 'PT Telkom Indonesia (Persero) Tbk', 'kind' => 'user_supplied',
            'report_period' => 'FY 2024', 'report_date' => '2025-04-17', 'currency' => 'IDR', 'multiplier' => '1000000000',
            'consolidated' => '1', 'source_name' => 'Annual Report Telkom 2024', 'publisher' => 'PT Telkom Indonesia (Persero) Tbk',
            'source_url' => self::OFFICIAL_REPORT, 'license_status' => 'public_disclosure', 'usage_rights' => 'internal_research',
            'rights_confirmed' => '1', 'fundamental_file' => UploadedFile::fake()->createWithContent('TLKM_FY2024_normalized.csv', $csv),
        ];

        $this->actingAs($user)->post('/ii/fundamentals/import', $payload)->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseCount('ii_issuer_documents', 1);
        $this->assertDatabaseCount('ii_issuer_periods', 2);
        $this->assertDatabaseHas('ii_issuer_documents', ['issuer_code' => 'TLKM.JK', 'quality_status' => 'COMPLETE', 'quality_score' => 100, 'consolidated' => 1]);
        $this->assertDatabaseHas('ii_issuer_periods', ['fiscal_year' => 2024, 'revenue' => '149967000000000.000000', 'restated' => 0]);

        $page = $this->actingAs($user)->get('/ii/fundamentals')->assertOk();
        $page->assertSee('PT Telkom Indonesia (Persero) Tbk')->assertSee('public_disclosure')->assertSee('internal_research')->assertSee('NOT_ADMITTED')->assertSee('HOLD')->assertSee('AUTHORITY NONE');

        $screen = $this->actingAs($user)->get('/ii/screener')->assertOk();
        $screen->assertSee('TLKM.JK')->assertSee('N/A')->assertSee('data 100/100')->assertSee('16.64%')->assertSee('0.37');
        $this->actingAs($user)->get('/ii/screener/export.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertSee('TLKM.JK')->assertSee('NOT_ADMITTED')->assertSee('NONE');
        $this->actingAs($user)->get('/ii/fundamentals/TLKM.JK/export.csv')->assertOk()->assertSee('149967000000000')->assertSee('internal_research');

        $payload['fundamental_file'] = UploadedFile::fake()->createWithContent('TLKM_duplicate.csv', $csv);
        $this->actingAs($user)->post('/ii/fundamentals/import', $payload)->assertSessionHasErrors('fundamental_file');
        $this->assertDatabaseCount('ii_issuer_documents', 1);
    }

    public function test_xbrl_maps_supported_ifrs_facts_and_preserves_negative_values(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();
        $xbrl = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:ifrs="https://xbrl.ifrs.org/taxonomy/2024-03-27/ifrs-full">
 <xbrli:context id="FY"><xbrli:entity><xbrli:identifier scheme="test">TEST</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:startDate>2024-01-01</xbrli:startDate><xbrli:endDate>2024-12-31</xbrli:endDate></xbrli:period></xbrli:context>
 <ifrs:Revenue contextRef="FY">1000</ifrs:Revenue><ifrs:ProfitLossAttributableToOwnersOfParent contextRef="FY">-25</ifrs:ProfitLossAttributableToOwnersOfParent><ifrs:Assets contextRef="FY">5000</ifrs:Assets><ifrs:Equity contextRef="FY">2000</ifrs:Equity><ifrs:CashFlowsFromUsedInOperatingActivities contextRef="FY">300</ifrs:CashFlowsFromUsedInOperatingActivities>
</xbrli:xbrl>
XML;
        $response = $this->actingAs($user)->post('/ii/fundamentals/import', [
            'ticker' => 'LOSS.JK', 'issuer_name' => 'Loss Example Tbk', 'kind' => 'user_supplied', 'report_period' => 'FY 2024', 'report_date' => '2025-03-01',
            'currency' => 'IDR', 'multiplier' => '1000000', 'source_name' => 'User-provided licensed XBRL', 'license_status' => 'user_provided',
            'usage_rights' => 'licensed_internal', 'rights_confirmed' => '1', 'fundamental_file' => UploadedFile::fake()->createWithContent('loss.xbrl', $xbrl),
        ]);
        $response->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseHas('ii_issuer_periods', ['fiscal_year' => 2024, 'net_income_attributable' => '-25000000.000000']);
        $this->actingAs($user)->get('/ii/fundamentals')->assertOk()->assertSee('LOSS.JK')->assertSee('N/A');
    }
}
