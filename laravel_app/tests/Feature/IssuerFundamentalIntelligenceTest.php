<?php

namespace Tests\Feature;

use App\Models\IssuerDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use Tests\TestCase;

class IssuerFundamentalIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICIAL_REPORT = 'https://www.telkom.co.id/minio/show/data/lampiran/1745244391951_original_Annual%20Report%20Telkom%202024%20%281%29.pdf';

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    private function metadata(array $overrides = []): array
    {
        return array_replace([
            'ticker' => 'TLKM.JK', 'issuer_name' => 'PT Telkom Indonesia (Persero) Tbk', 'kind' => 'user_supplied',
            'report_period' => 'FY 2024', 'report_date' => '2025-04-17', 'currency' => 'IDR', 'multiplier' => '1000000000',
            'consolidated' => '1', 'source_name' => 'Annual Report Telkom 2024', 'publisher' => 'PT Telkom Indonesia (Persero) Tbk',
            'source_url' => self::OFFICIAL_REPORT, 'license_status' => 'public_disclosure', 'usage_rights' => 'internal_research',
            'source_authenticity_status' => 'official_publisher', 'numeric_reconciliation_status' => 'verified_against_source',
            'provenance_confidence' => 'high', 'rights_confirmed' => '1',
        ], $overrides);
    }

    private function telkomCsv(): string
    {
        return <<<'CSV'
fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of
2023,2023-12-31,false,149216,24560,287042,156562,135744,60581,99062216600,68124,29007,77579,,
2024,2024-12-31,false,149967,23649,299675,162490,142094,61600,99062216600,76868,33905,75029,,
CSV;
    }

    public function test_reconciled_telkom_disclosure_flows_through_provenance_normalization_screener_and_exports(): void
    {
        Storage::fake('local');
        $user = $this->analyst();
        $payload = $this->metadata(['fundamental_file' => UploadedFile::fake()->createWithContent('TLKM_FY2024_reconciled.csv', $this->telkomCsv())]);

        $this->actingAs($user)->post('/ii/fundamentals/import', $payload)->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseCount('ii_issuer_documents', 1);
        $this->assertDatabaseCount('ii_issuer_periods', 2);
        $this->assertDatabaseHas('ii_issuer_documents', [
            'issuer_code' => 'TLKM.JK', 'quality_status' => 'COMPLETE', 'quality_score' => 100,
            'source_authenticity_status' => 'OFFICIAL_PUBLISHER', 'numeric_reconciliation_status' => 'VERIFIED_AGAINST_SOURCE',
            'licensing_assessment' => 'INTERNAL_RESEARCH_ONLY', 'provenance_confidence' => 'HIGH',
        ]);
        $this->assertDatabaseHas('ii_issuer_periods', [
            'fiscal_year' => 2024, 'revenue' => '149967000000000.000000', 'total_debt' => '76868000000000.000000',
            'ebitda' => '75029000000000.000000', 'total_equity' => '162490000000000.000000', 'market_price' => null,
        ]);

        $page = $this->actingAs($user)->get('/ii/fundamentals')->assertOk();
        $page->assertSee('COMPLETENESS 100/100')->assertSee('OFFICIAL_PUBLISHER')->assertSee('VERIFIED_AGAINST_SOURCE')
            ->assertSee('INTERNAL_RESEARCH_ONLY')->assertSee('N/A_MISSING_MARKET_PRICE')->assertSee('NOT_ADMITTED')->assertSee('AUTHORITY NONE');

        $screen = $this->actingAs($user)->get('/ii/screener')->assertOk();
        $screen->assertSee('TLKM.JK')->assertSee('N/A')->assertSee('16.64%')->assertSee('0.47');
        $screenCsv = $this->actingAs($user)->get('/ii/screener/export.csv')->assertOk()->getContent();
        $this->assertStringContainsString('N/A_MISSING_MARKET_PRICE', $screenCsv);
        $this->assertStringContainsString('NOT_ADMITTED,HOLD,NONE', $screenCsv);

        $export = $this->actingAs($user)->get('/ii/fundamentals/TLKM.JK/export.csv')->assertOk()->getContent();
        $reader = Reader::createFromString($export);
        $reader->setHeaderOffset(0);
        $rows = iterator_to_array($reader->getRecords(), false);
        $this->assertSame('149967000000000', $rows[1]['revenue']);
        $this->assertSame('76868000000000', $rows[1]['total_debt'] ?? '');
        $this->assertSame('', $rows[1]['market_price']);
        $this->assertSame('VERIFIED_AGAINST_SOURCE', $rows[1]['numeric_reconciliation']);
    }

    public function test_xbrl_requires_supported_namespace_context_units_accuracy_and_rejects_ambiguity(): void
    {
        Storage::fake('local');
        $user = $this->analyst();
        $xbrl = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:iso4217="http://www.xbrl.org/2003/iso4217" xmlns:ifrs="https://xbrl.ifrs.org/taxonomy/2024-03-27/ifrs-full">
 <xbrli:unit id="IDR"><xbrli:measure>iso4217:IDR</xbrli:measure></xbrli:unit>
 <xbrli:context id="D"><xbrli:entity><xbrli:identifier scheme="https://example.test/entity">TEST</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:startDate>2024-01-01</xbrli:startDate><xbrli:endDate>2024-12-31</xbrli:endDate></xbrli:period></xbrli:context>
 <xbrli:context id="I"><xbrli:entity><xbrli:identifier scheme="https://example.test/entity">TEST</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:instant>2024-12-31</xbrli:instant></xbrli:period></xbrli:context>
 <ifrs:Revenue contextRef="D" unitRef="IDR" decimals="0">1000</ifrs:Revenue><ifrs:ProfitLossAttributableToOwnersOfParent contextRef="D" unitRef="IDR" decimals="0">-25</ifrs:ProfitLossAttributableToOwnersOfParent><ifrs:Assets contextRef="I" unitRef="IDR" decimals="0">5000</ifrs:Assets><ifrs:Equity contextRef="I" unitRef="IDR" decimals="0">2000</ifrs:Equity><ifrs:CashFlowsFromUsedInOperatingActivities contextRef="D" unitRef="IDR" decimals="0">300</ifrs:CashFlowsFromUsedInOperatingActivities>
</xbrli:xbrl>
XML;
        $payload = $this->metadata([
            'ticker' => 'LOSS.JK', 'issuer_name' => 'Loss Example Tbk', 'multiplier' => '1', 'source_name' => 'Licensed XBRL',
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal', 'source_authenticity_status' => 'licensed_provider',
            'numeric_reconciliation_status' => 'unreconciled', 'provenance_confidence' => 'medium',
            'fundamental_file' => UploadedFile::fake()->createWithContent('loss.xbrl', $xbrl),
        ]);
        $this->actingAs($user)->post('/ii/fundamentals/import', $payload)->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseHas('ii_issuer_periods', ['fiscal_year' => 2024, 'net_income_attributable' => '-25.000000']);

        $contradictory = str_replace('</xbrli:xbrl>', '<ifrs:Revenue contextRef="D" unitRef="IDR" decimals="0">999</ifrs:Revenue></xbrli:xbrl>', $xbrl);
        $payload['fundamental_file'] = UploadedFile::fake()->createWithContent('conflict.xbrl', $contradictory);
        $this->actingAs($user)->post('/ii/fundamentals/import', $payload)->assertSessionHasErrors('fundamental_file');

        $wrongUnit = str_replace('unitRef="IDR" decimals="0">1000', 'unitRef="IDR" decimals="0">1000', str_replace('iso4217:IDR', 'iso4217:USD', $xbrl));
        $payload['fundamental_file'] = UploadedFile::fake()->createWithContent('wrong-unit.xbrl', $wrongUnit);
        $this->actingAs($user)->post('/ii/fundamentals/import', $payload)->assertSessionHasErrors('fundamental_file');

        $differentContext = str_replace('</xbrli:xbrl>', '<xbrli:context id="D2"><xbrli:entity><xbrli:identifier scheme="https://example.test/entity">TEST</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:startDate>2024-01-01</xbrli:startDate><xbrli:endDate>2024-12-31</xbrli:endDate></xbrli:period></xbrli:context></xbrli:xbrl>', $xbrl);
        $differentContext = preg_replace('/(<ifrs:Revenue\s+)contextRef="D"/', '$1contextRef="D2"', $differentContext, 1);
        $payload['fundamental_file'] = UploadedFile::fake()->createWithContent('mixed-context.xbrl', $differentContext);
        $this->actingAs($user)->post('/ii/fundamentals/import', $payload)->assertSessionHasErrors('fundamental_file');

        $nil = str_replace('xmlns:iso4217=', 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:iso4217=', $xbrl);
        $nil = str_replace('<ifrs:ProfitLossAttributableToOwnersOfParent contextRef="D" unitRef="IDR" decimals="0">-25</ifrs:ProfitLossAttributableToOwnersOfParent>', '<ifrs:ProfitLossAttributableToOwnersOfParent contextRef="D" unitRef="IDR" decimals="0" xsi:nil="true"/>', $nil);
        $nilPayload = array_merge($payload, ['ticker' => 'NIL.JK', 'issuer_name' => 'Nil Example Tbk', 'fundamental_file' => UploadedFile::fake()->createWithContent('nil.xbrl', $nil)]);
        $this->actingAs($user)->post('/ii/fundamentals/import', $nilPayload)->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseHas('ii_issuer_documents', ['issuer_code' => 'NIL.JK', 'quality_status' => 'PARTIAL']);
        $nilDocument = IssuerDocument::where('issuer_code', 'NIL.JK')->firstOrFail();
        $this->assertDatabaseHas('ii_issuer_periods', ['document_id' => $nilDocument->id, 'net_income_attributable' => null]);

        $malicious = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance">&xxe;</xbrli:xbrl>';
        $payload['fundamental_file'] = UploadedFile::fake()->createWithContent('xxe.xbrl', $malicious);
        $this->actingAs($user)->post('/ii/fundamentals/import', $payload)->assertSessionHasErrors('fundamental_file');
        $this->assertDatabaseCount('ii_issuer_documents', 2);
    }

    public function test_unsupported_taxonomy_is_archived_on_hold_without_numeric_output(): void
    {
        Storage::fake('local');
        $user = $this->analyst();
        $xbrl = <<<'XML'
<?xml version="1.0"?><xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:custom="https://issuer.test/taxonomy"><xbrli:unit id="IDR"><xbrli:measure>custom:IDR</xbrli:measure></xbrli:unit><xbrli:context id="D"><xbrli:entity><xbrli:identifier scheme="test">X</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:startDate>2024-01-01</xbrli:startDate><xbrli:endDate>2024-12-31</xbrli:endDate></xbrli:period></xbrli:context><custom:Revenue contextRef="D" unitRef="IDR" decimals="0">100</custom:Revenue></xbrli:xbrl>
XML;
        $payload = $this->metadata(['ticker' => 'CUSTOM.JK', 'issuer_name' => 'Custom Tbk', 'multiplier' => '1', 'fundamental_file' => UploadedFile::fake()->createWithContent('custom.xbrl', $xbrl)]);
        $this->actingAs($user)->post('/ii/fundamentals/import', $payload)->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseHas('ii_issuer_documents', ['issuer_code' => 'CUSTOM.JK', 'quality_status' => 'UNSUPPORTED_XBRL', 'quality_score' => 0]);
        $this->assertDatabaseCount('ii_issuer_periods', 0);

        $dimensioned = <<<'XML'
<?xml version="1.0"?><xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:xbrldi="http://xbrl.org/2006/xbrldi" xmlns:iso4217="http://www.xbrl.org/2003/iso4217" xmlns:ifrs="https://xbrl.ifrs.org/taxonomy/2024-03-27/ifrs-full" xmlns:axis="https://issuer.test/axis"><xbrli:unit id="IDR"><xbrli:measure>iso4217:IDR</xbrli:measure></xbrli:unit><xbrli:context id="D"><xbrli:entity><xbrli:identifier scheme="test">X</xbrli:identifier><xbrli:segment><xbrldi:explicitMember dimension="axis:SegmentAxis">axis:RetailMember</xbrldi:explicitMember></xbrli:segment></xbrli:entity><xbrli:period><xbrli:startDate>2024-01-01</xbrli:startDate><xbrli:endDate>2024-12-31</xbrli:endDate></xbrli:period></xbrli:context><ifrs:Revenue contextRef="D" unitRef="IDR" decimals="0">100</ifrs:Revenue></xbrli:xbrl>
XML;
        $dimensionPayload = $this->metadata(['ticker' => 'DIM.JK', 'issuer_name' => 'Dimensioned Tbk', 'multiplier' => '1', 'fundamental_file' => UploadedFile::fake()->createWithContent('dimensioned.xbrl', $dimensioned)]);
        $this->actingAs($user)->post('/ii/fundamentals/import', $dimensionPayload)->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseHas('ii_issuer_documents', ['issuer_code' => 'DIM.JK', 'quality_status' => 'UNSUPPORTED_XBRL']);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
    }

    public function test_versions_duplicates_cross_user_authorization_and_formula_safe_zero_export(): void
    {
        Storage::fake('local');
        $owner = $this->analyst();
        $other = $this->analyst();
        $csv = <<<'CSV'
fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of
2024,2024-12-31,false,100,0,500,300,250,0,1000,0,0,50,,
CSV;
        $payload = $this->metadata([
            'ticker' => 'SAFE.JK', 'issuer_name' => '=FORMULA()', 'source_name' => ' +SOURCE',
            'numeric_reconciliation_status' => 'user_attested', 'fundamental_file' => UploadedFile::fake()->createWithContent('safe.csv', $csv),
        ]);
        $this->actingAs($owner)->post('/ii/fundamentals/import', $payload)->assertRedirect('/ii/fundamentals');
        $payload['fundamental_file'] = UploadedFile::fake()->createWithContent('duplicate.csv', $csv);
        $this->actingAs($owner)->post('/ii/fundamentals/import', $payload)->assertSessionHasErrors('fundamental_file');
        $this->actingAs($other)->get('/ii/fundamentals/SAFE.JK/export.csv')->assertNotFound();

        $export = $this->actingAs($owner)->get('/ii/fundamentals/SAFE.JK/export.csv')->assertOk()->getContent();
        $reader = Reader::createFromString($export);
        $reader->setHeaderOffset(0);
        $row = iterator_to_array($reader->getRecords(), false)[0];
        $this->assertSame("'=FORMULA()", $row['issuer_name']);
        $this->assertSame("'+SOURCE", $row['source']);
        $this->assertSame('0', $row['net_income_attributable']);
        $this->assertSame('0', $row['operating_cash_flow']);
        $this->assertSame('', $row['market_price']);

        $version = str_replace(',100,0,500,', ',101,0,500,', $csv);
        $payload['usage_rights'] = 'licensed_internal';
        $payload['fundamental_file'] = UploadedFile::fake()->createWithContent('version-2.csv', $version);
        $this->actingAs($owner)->post('/ii/fundamentals/import', $payload)->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseCount('ii_issuer_documents', 2);
        $this->assertDatabaseCount('ii_issuer_sources', 2);
    }

    public function test_market_price_requires_as_of_and_public_template_is_synthetic(): void
    {
        Storage::fake('local');
        $user = $this->analyst();
        $invalid = str_replace('75029,,', '75029,2710,', $this->telkomCsv());
        $payload = $this->metadata(['fundamental_file' => UploadedFile::fake()->createWithContent('missing-price-date.csv', $invalid)]);
        $this->actingAs($user)->post('/ii/fundamentals/import', $payload)->assertSessionHasErrors('fundamental_file');

        $template = $this->actingAs($user)->get('/ii/fundamentals/example')->assertOk();
        $template->assertHeader('Content-Disposition', 'attachment; filename="II_ISSUER_FUNDAMENTAL_SYNTHETIC_TEMPLATE.csv"')
            ->assertDontSee('TLKM')->assertDontSee('149967')->assertSee('market_price_as_of');
    }
}
