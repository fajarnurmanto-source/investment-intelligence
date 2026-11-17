<?php

namespace Tests\Feature;

use App\Models\IssuerDocument;
use App\Models\IssuerImportBatch;
use App\Models\IssuerImportItem;
use App\Models\User;
use App\Services\FundamentalSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiIssuerFundamentalV2Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    private function metadata(string $ticker, string $sector, string $source): array
    {
        return [
            'ticker' => $ticker, 'issuer_name' => $ticker.' Example', 'sector' => $sector, 'kind' => 'user_supplied',
            'report_period' => 'FY 2024', 'fiscal_period_type' => 'annual', 'report_date' => '2025-03-01',
            'currency' => 'IDR', 'multiplier' => '1', 'consolidated' => true,
            'source_name' => $source, 'publisher' => 'Test Publisher', 'source_url' => 'https://example.test/'.$ticker,
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'licensed_provider', 'numeric_reconciliation_status' => 'verified_against_source',
            'provenance_confidence' => 'high', 'redistribution_allowed' => false, 'rights_confirmed' => true,
        ];
    }

    private function csv(float $revenue2024 = 120): string
    {
        return "fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of\n"
            ."2023,2023-12-31,false,100,10,500,300,250,50,1000,100,20,40,,\n"
            ."2024,2024-12-31,false,{$revenue2024},12,550,320,270,60,1000,110,25,45,,\n";
    }

    public function test_batch_ingestion_cross_issuer_screen_price_provenance_and_conflict_are_auditable(): void
    {
        Storage::fake('local');
        $user = $this->analyst();
        $items = [
            ['file' => 'AAA.csv', 'metadata' => $this->metadata('AAA.JK', 'Industrials', 'AAA licensed filing')],
            ['file' => 'BBB.csv', 'metadata' => $this->metadata('BBB.JK', 'Consumer', 'BBB licensed filing')],
            ['file' => 'CCC.csv', 'metadata' => $this->metadata('CCC.JK', 'Industrials', 'CCC licensed filing')],
        ];
        $manifest = UploadedFile::fake()->createWithContent('manifest.json', json_encode([
            'schema' => 'ii.issuer-batch.v2', 'items' => $items,
        ], JSON_THROW_ON_ERROR));
        $files = [
            UploadedFile::fake()->createWithContent('AAA.csv', $this->csv()),
            UploadedFile::fake()->createWithContent('BBB.csv', $this->csv(90)),
            UploadedFile::fake()->createWithContent('CCC.csv', $this->csv(150)),
        ];

        $this->actingAs($user)->post('/ii/fundamentals/batches', ['manifest' => $manifest, 'issuer_files' => $files])
            ->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseCount('ii_issuer_documents', 3);
        $this->assertDatabaseCount('ii_issuer_periods', 6);
        $this->assertDatabaseHas('ii_issuer_import_batches', ['status' => 'COMPLETED', 'succeeded_items' => 3, 'failed_items' => 0]);

        $beforePrice = $this->actingAs($user)->get('/ii/screener?sector=Industrials&period=2024&sort=revenue_growth_pct&sort_direction=desc')
            ->assertOk()->assertSee('AAA.JK')->assertSee('CCC.JK')->assertDontSee('BBB.JK')->assertSee('N/A_MISSING_MARKET_PRICE');
        $this->assertStringContainsString('CCC.JK', $beforePrice->getContent());

        $this->actingAs($user)->post('/ii/fundamentals/market-prices', [
            'ticker' => 'AAA.JK', 'observed_on' => '2025-01-02', 'currency' => 'IDR', 'price' => '1000',
            'unit' => 'per_share', 'adjustment_status' => 'unadjusted', 'source_name' => 'Licensed Closing Price',
            'publisher' => 'Licensed Vendor', 'source_url' => 'https://example.test/prices/AAA/2025-01-02',
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'licensed_provider', 'provenance_confidence' => 'high',
            'rights_confirmed' => '1',
        ])->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseHas('ii_issuer_market_prices', [
            'issuer_code' => 'AAA.JK', 'observed_on' => '2025-01-02 00:00:00', 'currency' => 'IDR',
            'usage_rights' => 'licensed_internal', 'source_authenticity_status' => 'LICENSED_PROVIDER',
        ]);
        $this->actingAs($user)->get('/ii/screener?sector=Industrials&period=2024&sort=pe_ratio&sort_direction=asc')
            ->assertOk()->assertSee('N/A_SHARE_BASIS_REVIEW_MISSING')->assertSee('Licensed Closing Price');

        $conflicting = $this->metadata('AAA.JK', 'Industrials', 'AAA second official source');
        $conflicting['restates_document_id'] = IssuerDocument::where('issuer_code', 'AAA.JK')->value('id');
        $conflicting['fundamental_file'] = UploadedFile::fake()->createWithContent('AAA-conflict.csv', $this->csv(121));
        $this->actingAs($user)->post('/ii/fundamentals/import', $conflicting)->assertRedirect('/ii/fundamentals');
        $this->assertDatabaseCount('ii_issuer_documents', 4);
        $this->assertDatabaseHas('ii_issuer_documents', ['issuer_code' => 'AAA.JK', 'restates_document_id' => $conflicting['restates_document_id']]);
        $this->assertSame(2, IssuerDocument::where('issuer_code', 'AAA.JK')->where('conflict_status', 'VALUE_CONFLICT')->count());
        $this->actingAs($user)->get('/ii/fundamentals')->assertOk()->assertSee('VALUE_CONFLICT')->assertSee('Batch ingestion');

        $csv = $this->actingAs($user)->get('/ii/screener/export.csv?sector=Industrials&period=2024')->assertOk()->getContent();
        $this->assertStringContainsString('market_price_source,market_price_rights,valuation_status', $csv);
        $this->assertStringContainsString('NOT_ADMITTED,HOLD,NONE', $csv);
        $csvRows = array_values(array_filter(preg_split('/\R/', trim($csv))));
        $this->assertCount(count(str_getcsv($csvRows[0])), str_getcsv($csvRows[1]), 'CSV header and data columns must align.');
    }

    public function test_failed_batch_item_can_be_retried_only_by_owner_and_keeps_item_level_error(): void
    {
        // The invalid CSV is intentional. Suppress its expected exception
        // trace for this test only; preserve regular logging in production.
        config()->set('logging.default', 'null');
        Storage::fake('local');
        $owner = $this->analyst();
        $other = $this->analyst();
        $manifest = UploadedFile::fake()->createWithContent('manifest.json', json_encode([
            'schema' => 'ii.issuer-batch.v2',
            'items' => [['file' => 'broken.csv', 'metadata' => $this->metadata('BROKEN.JK', 'Other', 'Broken source')]],
        ], JSON_THROW_ON_ERROR));
        $file = UploadedFile::fake()->createWithContent('broken.csv', "fiscal_year,unknown\n2024,1\n");
        $this->actingAs($owner)->post('/ii/fundamentals/batches', ['manifest' => $manifest, 'issuer_files' => [$file]])
            ->assertRedirect('/ii/fundamentals');
        $batch = IssuerImportBatch::firstOrFail();
        $this->assertSame('FAILED', $batch->status);
        $item = IssuerImportItem::firstOrFail();
        $this->assertSame(1, $item->attempts);
        $this->assertSame('CSV memuat kolom yang tidak dikenal.', $item->error_message);

        $this->actingAs($other)->post('/ii/fundamentals/batches/'.$batch->id.'/retry')->assertNotFound();
        $this->actingAs($owner)->post('/ii/fundamentals/batches/'.$batch->id.'/retry')->assertRedirect('/ii/fundamentals');
        $this->assertSame(2, $item->fresh()->attempts);
        $this->assertSame('FAILED', $batch->fresh()->status);
        $this->assertDatabaseCount('ii_issuer_documents', 0);
    }

    public function test_split_adjusted_price_is_held_and_older_late_import_does_not_replace_latest_period(): void
    {
        Storage::fake('local');
        $user = $this->analyst();
        $latest = $this->metadata('PERIOD.JK', 'Industrials', 'FY2024 official source');
        $latest['fundamental_file'] = UploadedFile::fake()->createWithContent('period-2024.csv', $this->csv());
        $this->actingAs($user)->post('/ii/fundamentals/import', $latest)->assertRedirect('/ii/fundamentals');

        $older = $this->metadata('PERIOD.JK', 'Industrials', 'FY2022 late official source');
        $older['report_period'] = 'FY 2022';
        $older['report_date'] = '2023-03-01';
        $older['fundamental_file'] = UploadedFile::fake()->createWithContent('period-2022.csv',
            "fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of\n"
            ."2021,2021-12-31,false,80,8,450,280,230,42,1000,90,18,35,,\n"
            ."2022,2022-12-31,false,90,9,470,290,240,45,1000,95,19,37,,\n");
        $this->actingAs($user)->post('/ii/fundamentals/import', $older)->assertRedirect('/ii/fundamentals');

        $snapshot = app(FundamentalSnapshotService::class)->latestByTicker((int) $user->id)['PERIOD.JK'];
        $this->assertSame(2024, $snapshot['latest_year']);
        $this->assertSame('FY2024 official source', $snapshot['source_name']);
        $this->assertSame(2, $snapshot['version_count']);

        $this->actingAs($user)->post('/ii/fundamentals/market-prices', [
            'ticker' => 'PERIOD.JK', 'observed_on' => '2025-01-02', 'currency' => 'IDR', 'price' => '1000',
            'unit' => 'per_share', 'adjustment_status' => 'split_adjusted', 'source_name' => 'Licensed adjusted price',
            'publisher' => 'Licensed Vendor', 'source_url' => 'https://example.test/prices/PERIOD/2025-01-02',
            'license_status' => 'licensed', 'usage_rights' => 'licensed_internal',
            'source_authenticity_status' => 'licensed_provider', 'provenance_confidence' => 'high',
            'rights_confirmed' => '1',
        ])->assertRedirect('/ii/fundamentals');
        $snapshot = app(FundamentalSnapshotService::class)->latestByTicker((int) $user->id)['PERIOD.JK'];
        $this->assertSame('N/A_SPLIT_ADJUSTMENT_UNALIGNED', $snapshot['valuation_status']);
        $this->assertNull($snapshot['pe_ratio']);
        $this->assertNull($snapshot['pbv_ratio']);
        $this->assertNull($snapshot['ev_ebitda_ratio']);
        $this->assertSame('NOT_ADMITTED', $snapshot['gate']);
        $this->assertSame('HOLD', $snapshot['decision']);
        $this->assertSame('NONE', $snapshot['trading_authority']);
    }
}
