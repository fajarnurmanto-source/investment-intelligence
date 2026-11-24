<?php

namespace Tests\Feature;

use App\Models\IssuerDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IdxFilingEvidenceV12Test extends TestCase
{
    use RefreshDatabase;

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();

        return $user;
    }

    private function filing(array $overrides = [], string $pdf = "%PDF-1.7\n1 0 obj\n<< >>\nendobj\n%%EOF\n"): array
    {
        return array_merge([
            'idx_ticker' => 'BBCA',
            'idx_issuer_name' => 'Bank Central Asia Tbk',
            'idx_report_period' => 'FY 2025',
            'idx_report_date' => '2026-03-15',
            'idx_currency' => 'IDR',
            'idx_consolidated' => '1',
            'idx_source_url' => 'https://www.idx.co.id/StaticData/NewsAndAnnouncement/ANNOUNCEMENTSTOCK/From_EREP/202603/sample.pdf',
            'idx_rights_confirmed' => '1',
            'idx_filing_file' => UploadedFile::fake()->createWithContent('report.pdf', $pdf),
        ], $overrides);
    }

    public function test_authenticated_idx_evidence_intake_is_immutable_document_only_and_user_attested(): void
    {
        Storage::fake('local');
        $owner = $this->analyst();
        $other = $this->analyst();
        $pdf = "%PDF-1.7\n1 0 obj\n<< >>\nendobj\n%%EOF\n";

        $this->actingAs($owner)->post('/ii/fundamentals/idx-filings', $this->filing())
            ->assertRedirect('/ii/fundamentals?workspace=activation')
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ii_issuer_documents', 1);
        $this->assertDatabaseCount('ii_issuer_periods', 0);
        $document = IssuerDocument::with('source')->firstOrFail();
        $this->assertSame((int) $owner->id, (int) $document->user_id);
        $this->assertSame('BBCA.JK', $document->issuer_code);
        $this->assertSame('FY 2025', $document->report_period);
        $this->assertSame('DOCUMENT_ONLY', $document->quality_status);
        $this->assertSame(0, $document->quality_score);
        $this->assertSame('USER_ATTESTED', $document->source_authenticity_status);
        $this->assertSame('UNRECONCILED', $document->numeric_reconciliation_status);
        $this->assertSame('LOW', $document->provenance_confidence);
        $this->assertFalse($document->source->redistribution_allowed);
        $this->assertSame('INTERNAL_RESEARCH_ONLY', $document->licensing_assessment);
        $this->assertSame('internal_research', $document->source->usage_rights);
        $this->assertSame(hash('sha256', $pdf), $document->sha256);
        $this->assertSame($pdf, Storage::disk('local')->get($document->storage_path));
        $this->assertTrue($document->provenance['reported_by_user']);
        $this->assertSame('https://www.idx.co.id/id/syarat-penggunaan', $document->provenance['terms_url']);
        $this->assertSame('NONE', $document->conflict_status);
        $this->actingAs($owner)->get('/ii/fundamentals?workspace=activation')
            ->assertOk()->assertSee('BBCA.JK')->assertSee('V12 · IDX/BEI FILING')
            ->assertSee('USER_ATTESTED');
        $this->actingAs($other)->get('/ii/fundamentals?workspace=activation')
            ->assertOk()->assertDontSee('BBCA.JK');
    }

    public function test_idx_source_url_is_exact_https_pdf_on_official_host_with_no_fetch(): void
    {
        Storage::fake('local');
        $owner = $this->analyst();
        foreach ([
            'https://www.idx.co.id.evil.test/filing.pdf',
            'https://www.idx.co.id@evil.test/filing.pdf',
            'http://www.idx.co.id/filing.pdf',
            'https://www.idx.co.id:8443/filing.pdf',
            'https://www.idx.co.id/filing.pdf#fake',
            'https://evil.test/filing.pdf',
            'https://www.idx.co.id/filing.html',
        ] as $url) {
            $this->actingAs($owner)->post('/ii/fundamentals/idx-filings',
                $this->filing(['idx_source_url' => $url]))
                ->assertRedirect('/ii/fundamentals?workspace=activation')
                ->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('ii_issuer_documents', 0);
        $this->assertDatabaseCount('ii_issuer_sources', 0);
    }

    public function test_html_disguised_as_pdf_is_rejected_and_original_checksum_cannot_repeat(): void
    {
        Storage::fake('local');
        $owner = $this->analyst();
        $this->actingAs($owner)->post('/ii/fundamentals/idx-filings',
            $this->filing([], '<html>fake PDF</html>'))
            ->assertRedirect('/ii/fundamentals?workspace=activation')
            ->assertSessionHasErrors();
        $this->assertDatabaseCount('ii_issuer_documents', 0);

        $this->actingAs($owner)->post('/ii/fundamentals/idx-filings', $this->filing())
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/ii/fundamentals/idx-filings', $this->filing())
            ->assertRedirect('/ii/fundamentals?workspace=activation')
            ->assertSessionHasErrors();
        $this->assertDatabaseCount('ii_issuer_documents', 1);
    }

    public function test_idx_filing_respects_rights_gate_and_authentication_and_period_semantics(): void
    {
        Storage::fake('local');
        $owner = $this->analyst();
        $this->post('/ii/fundamentals/idx-filings', $this->filing())
            ->assertRedirect('/login');
        $this->actingAs($owner)->post('/ii/fundamentals/idx-filings',
            $this->filing(['idx_rights_confirmed' => null]))
            ->assertSessionHasErrors('idx_rights_confirmed');
        $this->assertDatabaseCount('ii_issuer_documents', 0);

        $this->actingAs($owner)->post('/ii/fundamentals/idx-filings',
            $this->filing(['idx_report_period' => 'Q1 2026']))
            ->assertSessionHasNoErrors();
        $document = IssuerDocument::firstOrFail();
        $this->assertSame('quarterly', $document->fiscal_period_type);
        $this->assertSame('Q1 2026', $document->report_period);
    }
}
