<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use Tests\TestCase;

class SecCompanyFactsNormalizationV10Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();
        return $user;
    }

    private function item(string $accn, string $start, string $end, int $value, string $unit = 'USD'): array
    {
        return [
            'start' => $start, 'end' => $end, 'val' => $value, 'accn' => $accn,
            'fy' => (int) substr($end, 0, 4), 'fp' => 'FY',
            'form' => '10-K', 'filed' => ((int) substr($end, 0, 4) + 1).'-02-15',
        ];
    }

    private function fixture(): array
    {
        $a = '0000000001-25-000001';
        $b = '0000000001-24-000001';
        $v = function (string $metric, string $unit, int $v23, int $v24, bool $instant = false) use ($a, $b): array {
            $prev = $this->item($b, '2023-01-01', '2023-12-31', $v23, $unit);
            $last = $this->item($a, '2024-01-01', '2024-12-31', $v24, $unit);
            if ($instant) {
                unset($prev['start'], $last['start']);
            }
            return ['units' => [$unit => [$prev, $last]]];
        };
        return [
            'cik' => 320193, 'entityName' => 'Synthetic SEC XBRL Test Co',
            'facts' => [
                'us-gaap' => [
                    'RevenueFromContractWithCustomerExcludingAssessedTax' => $v('revenue', 'USD', 1000, 1200),
                    'NetIncomeLoss' => $v('netIncome', 'USD', 100, 110),
                    'Assets' => $v('assets', 'USD', 4000, 4500, true),
                    'StockholdersEquity' => $v('equity', 'USD', 2000, 2100, true),
                    'NetCashProvidedByUsedInOperatingActivities' => $v('ocf', 'USD', 300, 320),
                    'CommonStockSharesOutstanding' => $v('shares', 'shares', 100, 100, true),
                    'CashAndCashEquivalentsAtCarryingValue' => $v('cash', 'USD', 250, 260, true),
                ],
            ],
        ];
    }

    private function uploaded(array $payload): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('companyfacts.json',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function test_offline_sec_conversion_produces_review_csv_and_no_persistent_data(): void
    {
        Storage::fake('local');
        $user = $this->analyst();
        $file = $this->uploaded($this->fixture());
        $hash = hash_file('sha256', $file->getRealPath());

        $response = $this->actingAs($user)->post('/ii/fundamentals/sec-companyfacts/normalize', [
            'sec_companyfacts_json' => $file,
        ])->assertOk()->assertHeader('X-II-Review-Required', 'true')
            ->assertHeader('X-II-Source-Sha256', $hash)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('CIK0000320193_REVIEW.csv', $response->headers->get('Content-Disposition'));
        $this->assertSame('USER_UPLOAD_REVIEW_REQUIRED',
            $response->headers->get('X-II-Lineage-Status'));
        $this->assertSame('LOCAL_REVIEW_ONLY_NOT_CLEARED_FOR_REDISTRIBUTION',
            $response->headers->get('X-II-Export-Scope'));
        $this->assertSame(hash('sha256', $response->getContent()),
            $response->headers->get('X-II-Content-SHA256'));
        $lineageNode = $response->headers->get('X-II-Lineage-Node');
        $registry = app(\App\Services\LocalLineageRegistry::class);
        $this->assertTrue($registry->verifyTrace($user->id, $lineageNode));
        $this->assertCount(2, $registry->trace($user->id, $lineageNode)['nodes']);
        $this->assertFalse($registry->allows($user->id, $lineageNode, 'export'));

        $reader = Reader::createFromString($response->getContent());
        $reader->setHeaderOffset(0);
        $rows = iterator_to_array($reader->getRecords(), false);
        $this->assertCount(2, $rows);
        $this->assertSame('2023', $rows[0]['fiscal_year']);
        $this->assertSame('2024-12-31', $rows[1]['period_end']);
        $this->assertSame('1200', $rows[1]['revenue']);
        $this->assertSame('4500', $rows[1]['total_assets']);
        $this->assertSame('100', $rows[1]['shares_outstanding']);
        $this->assertSame('', $rows[1]['market_price']);
        $this->assertSame('', $rows[1]['total_debt']);
        $this->assertSame('', $rows[1]['ebitda']);

        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
        $this->assertDatabaseCount('ii_issuer_market_prices', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Human review and attestation are a separate step. Reuse the
        // canonical existing issuer CSV import path for actual persistence.
        $csvFile = UploadedFile::fake()->createWithContent('SEC_REVIEW.csv', $response->getContent());
        $this->actingAs($user)->post('/ii/fundamentals/import', [
            'ticker' => 'SECEX', 'issuer_name' => 'Synthetic SEC XBRL Test Co',
            'sector' => 'Technology', 'kind' => 'user_supplied',
            'report_period' => 'FY 2024', 'report_date' => '2025-02-15',
            'fiscal_period_type' => 'annual', 'currency' => 'USD', 'multiplier' => '1',
            'consolidated' => '1', 'source_name' => 'SEC companyfacts user-reviewed synthetic fixture',
            'source_url' => 'https://data.sec.gov/api/xbrl/companyfacts/CIK0000320193.json',
            'license_status' => 'public_disclosure', 'usage_rights' => 'internal_research',
            'source_authenticity_status' => 'user_attested',
            'numeric_reconciliation_status' => 'unreconciled',
            'provenance_confidence' => 'low', 'rights_confirmed' => '1',
            'fundamental_file' => $csvFile,
        ])->assertRedirect('/ii/fundamentals')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ii_issuer_documents', 1);
        $this->assertDatabaseCount('ii_issuer_periods', 2);
        $this->assertDatabaseHas('ii_issuer_periods', ['fiscal_year' => 2024, 'revenue' => '1200.000000']);
    }

    public function test_mismatched_accessions_are_never_fused_and_quarterly_facts_are_ignored(): void
    {
        Storage::fake('local');
        $user = $this->analyst();
        $fixture = $this->fixture();
        $asset = &$fixture['facts']['us-gaap']['Assets']['units']['USD'][1];
        $asset['accn'] = '0000000001-25-000002';
        $asset['filed'] = '2025-03-30';
        $lastRevenue = &$fixture['facts']['us-gaap']['RevenueFromContractWithCustomerExcludingAssessedTax']['units']['USD'][1];
        $lastRevenue['val'] = 1200;

        $fixture['facts']['us-gaap']['Revenues'] = ['units' => ['USD' => [[
            'start' => '2024-10-01', 'end' => '2024-12-31',
            'val' => 999999, 'accn' => '0000000001-25-000003', 'form' => '10-Q',
            'fp' => 'Q4', 'fy' => 2024, 'filed' => '2025-01-01',
        ]]]];

        $response = $this->actingAs($user)->post('/ii/fundamentals/sec-companyfacts/normalize', [
            'sec_companyfacts_json' => $this->uploaded($fixture),
        ])->assertOk();
        $reader = Reader::createFromString($response->getContent());
        $reader->setHeaderOffset(0);
        $rows = iterator_to_array($reader->getRecords(), false);
        $year = collect($rows)->firstWhere('fiscal_year', '2024');
        $this->assertNotNull($year);
        $this->assertFalse($year['revenue'] === '1200' && $year['total_assets'] === '4500');
        $this->assertNotSame('999999', $year['revenue']);
    }

    public function test_non_sec_json_and_unauthorized_requests_fail_closed(): void
    {
        Storage::fake('local');
        $this->post('/ii/fundamentals/sec-companyfacts/normalize', [
            'sec_companyfacts_json' => $this->uploaded($this->fixture()),
        ])->assertRedirect();

        $user = $this->analyst();
        foreach ([
            ['cik' => 320193, 'entityName' => 'Fake', 'facts' => ['ifrs-full' => []]],
            ['cik' => 'not-a-cik', 'entityName' => 'Fake', 'facts' => ['us-gaap' => []]],
        ] as $bad) {
            $this->actingAs($user)->post('/ii/fundamentals/sec-companyfacts/normalize', [
                'sec_companyfacts_json' => $this->uploaded($bad),
            ])->assertRedirect('/ii/fundamentals?workspace=activation')
                ->assertSessionHasErrors('sec_companyfacts_json');
        }
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
