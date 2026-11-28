<?php

namespace Tests\Feature;

use App\Models\IssuerMarketPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use Tests\TestCase;

class RealSourceActivationV9Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    private function issuer(User $user, string $ticker, string $extension = 'csv', string $rights = 'internal_research', string $issuerName = 'Coverage Example Tbk'): void
    {
        $csv = "fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,total_equity,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price,market_price_as_of\n"
            ."2023,2023-12-31,false,100,10,500,300,250,50,1000,100,20,40,,\n"
            ."2024,2024-12-31,false,120,12,550,320,270,60,1000,110,25,45,,\n";
        // Separate immutable source checksums even when synthetic schema and metrics match.
        $csv = str_replace('2023,2023-12-31,false,100,10',
            '2023,2023-12-31,false,'.(100 + abs(crc32($ticker) % 100000)).',10', $csv);
        $body = $extension === 'pdf' ? '%PDF-1.4 archive only for '.$ticker : $csv;
        $this->actingAs($user)->post('/ii/fundamentals/import', [
            'ticker' => $ticker, 'issuer_name' => $issuerName,
            'sector' => 'Industrials', 'kind' => 'user_supplied', 'report_period' => 'FY 2024',
            'fiscal_period_type' => 'annual', 'report_date' => '2025-03-01', 'currency' => 'IDR',
            'multiplier' => '1', 'consolidated' => '1',
            'source_name' => 'Publisher declaration', 'publisher' => 'Fixture publisher',
            'source_url' => 'https://example.test/report/'.rawurlencode($ticker),
            'license_status' => $rights === 'unknown' ? 'unknown' : 'public_disclosure',
            'usage_rights' => $rights, 'source_authenticity_status' => 'official_publisher',
            'numeric_reconciliation_status' => 'verified_against_source',
            'provenance_confidence' => 'high', 'rights_confirmed' => '1',
            'fundamental_file' => UploadedFile::fake()->createWithContent('issuer-'.$ticker.'.'.$extension, $body),
        ])->assertRedirect('/ii/fundamentals')->assertSessionHasNoErrors();
    }

    private function price(User $user, string $ticker): void
    {
        IssuerMarketPrice::create([
            'user_id' => $user->id, 'issuer_code' => $ticker, 'observed_on' => '2025-04-01',
            'currency' => 'IDR', 'price' => '100', 'unit' => 'per_share',
            'adjustment_status' => 'UNADJUSTED_CLOSE', 'source_name' => 'Attested provider',
            'source_url' => 'https://example.test/price', 'license_status' => 'licensed',
            'usage_rights' => 'licensed_internal', 'source_authenticity_status' => 'LICENSED_PROVIDER',
            'provenance_confidence' => 'HIGH', 'redistribution_allowed' => false,
            'sha256' => hash('sha256', $user->id.'|'.$ticker), 'imported_at' => now(),
        ]);
    }

    public function test_source_activation_reports_evidence_gaps_without_claiming_independent_verification(): void
    {
        Storage::fake('local');
        $owner = $this->analyst();
        $other = $this->analyst();
        $this->issuer($owner, 'COVER.JK');
        $this->issuer($owner, 'ARCHIVE.JK', 'pdf');
        $this->issuer($owner, 'RIGHTS.JK', rights: 'unknown');
        $this->price($owner, 'PRICEONLY.JK');
        $this->issuer($other, 'HIDDEN.JK');
        // The test switches authenticated users within one browser session.
        // Clear the other user's prior flash toast before checking DB isolation.
        session()->forget('fundamental_notice');

        $page = $this->actingAs($owner)->get('/ii/fundamentals?workspace=activation')
            ->assertOk()
            ->assertSee('Aktivasi sumber dan cakupan emiten')
            ->assertSee('COVER.JK')
            ->assertSee('ARCHIVE.JK')
            ->assertSee('RIGHTS.JK')
            ->assertSee('PRICEONLY.JK')
            ->assertSee('LICENSED_PRICE_REQUIRED')
            ->assertSee('NUMERIC_EXTRACTION_REQUIRED')
            ->assertSee('RIGHTS_REVIEW_REQUIRED')
            ->assertSee('DOCUMENT_REQUIRED')
            ->assertSee('NOT_ADMITTED')
            ->assertSee('bukan rekomendasi saham atau izin perdagangan')
            ->assertSee('https://data.idx.co.id/', false)
            ->assertDontSee('HIDDEN.JK');
        $this->assertStringContainsString('keterangan yang dilaporkan pengguna', $page->getContent());
        $this->assertStringContainsString('private', $page->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->actingAs($other)->get('/ii/fundamentals?workspace=activation')
            ->assertOk()->assertSee('HIDDEN.JK')->assertDontSee('COVER.JK');
    }

    public function test_activation_export_is_private_scoped_formula_safe_and_anonymous_forbidden(): void
    {
        Storage::fake('local');
        $owner = $this->analyst();
        $other = $this->analyst();
        $this->issuer($owner, 'SAFE.JK', issuerName: '=HYPERLINK("https://bad.test","Open")');
        $this->issuer($other, 'SECRETX.JK');

        auth()->logout();
        $this->get('/ii/fundamentals/coverage/export.csv')->assertRedirect('/login');

        $response = $this->actingAs($owner)->get('/ii/fundamentals/coverage/export.csv')
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $csv = $response->getContent();
        $reader = Reader::createFromString($csv);
        $reader->setHeaderOffset(0);
        $rows = iterator_to_array($reader->getRecords(), false);
        $this->assertCount(1, $rows);
        $this->assertSame('SAFE.JK', $rows[0]['ticker']);
        $this->assertStringStartsWith("'", $rows[0]['issuer_name']);
        $this->assertSame('LICENSED_PRICE_REQUIRED', $rows[0]['next_action']);
        $this->assertSame('NOT_ADMITTED', $rows[0]['gate']);
        $this->assertSame('HOLD', $rows[0]['decision']);
        $this->assertSame('NONE', $rows[0]['trading_authority']);
        $this->assertStringNotContainsString('SECRETX.JK', $csv);
    }

    public function test_activation_empty_account_does_not_claim_market_coverage(): void
    {
        $user = $this->analyst();
        $this->actingAs($user)->get('/ii/fundamentals?workspace=activation')
            ->assertOk()
            ->assertSee('Belum ada dokumen fundamental atau observasi harga')
            ->assertDontSee('962 emiten');
        $csv = $this->actingAs($user)->get('/ii/fundamentals/coverage/export.csv')->assertOk()->getContent();
        $this->assertSame(1, count(array_filter(explode("\n", trim($csv)))));
    }
}
