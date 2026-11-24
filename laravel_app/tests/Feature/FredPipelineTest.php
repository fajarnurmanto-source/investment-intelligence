<?php

namespace Tests\Feature;

use App\Events\EconomicDataUpdated;
use App\Models\User;
use App\Services\FredIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FredPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_data_contract_normalizes_persists_and_reports(): void
    {
        Event::fake([EconomicDataUpdated::class]);
        Cache::flush();
        Http::fake([
            'fred.stlouisfed.org/*' => Http::response($this->cpiCsv(), 200, ['Content-Type' => 'text/csv']),
        ]);

        $run = app(FredIngestionService::class)->ingest('CPIAUCSL');
        $this->assertSame('SUCCEEDED', $run->status);
        $this->assertSame(13, $run->records_written);
        $this->assertDatabaseCount('ii_economic_observations', 13);
        $this->assertDatabaseHas('ii_economic_observations', [
            'series_id' => 'CPIAUCSL',
            'observed_on' => '2026-01-01 00:00:00',
            'quality_status' => 'VALID',
        ]);
        Event::assertDispatched(EconomicDataUpdated::class);

        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();
        $this->actingAs($user)->get('/ii/macro')->assertOk()->assertSee('Inflasi YoY')->assertSee('CPIAUCSL');
        $this->actingAs($user)->get('/ii/macro/report/rights')->assertOk()
            ->assertJsonPath('status', 'DENIED')
            ->assertJsonPath('issues.0', 'CPIAUCSL:RIGHTS_REVIEW_REQUIRED');
        $this->get('/ii/macro/report.csv')->assertForbidden();
        $this->assertDatabaseCount('ii_lineage_nodes', 0);

        // This is a synthetic CI-only policy receipt. Deployment MUST obtain
        // a genuine, scoped rights review before setting this configuration.
        config()->set('ii.fred.report_rights.CPIAUCSL', [
            'status' => 'REVIEWED_ALLOWED',
            'scope' => 'LOCAL_PERSONAL_NONCOMMERCIAL_CSV',
            'receipt_sha256' => hash('sha256', 'synthetic-ci-only-rights-review'),
            'source_owner' => 'U.S. Bureau of Labor Statistics',
        ]);
        $response = $this->get('/ii/macro/report.csv')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-II-Rights-Scope', 'LOCAL_PERSONAL_NONCOMMERCIAL_CSV');
        $nodeId = $response->headers->get('X-II-Lineage-Node');
        $this->assertNotEmpty($nodeId);
        $this->assertSame(hash('sha256', $response->getContent()),
            $response->headers->get('X-II-Content-SHA256'));
        $registry = app(\App\Services\LocalLineageRegistry::class);
        $this->assertTrue($registry->verifyTrace($user->id, $nodeId));
        $this->assertCount(2, $registry->trace($user->id, $nodeId)['nodes']);
        $this->assertFalse($registry->allows($user->id, $nodeId, 'cloud'));
    }

    public function test_macro_rights_preflight_rejects_spoofed_source_and_invalid_review_receipt(): void
    {
        Event::fake([EconomicDataUpdated::class]);
        Cache::flush();
        Http::fake([
            'fred.stlouisfed.org/*' => Http::response($this->cpiCsv(), 200, ['Content-Type' => 'text/csv']),
        ]);
        app(FredIngestionService::class)->ingest('CPIAUCSL');
        $user = User::factory()->create();
        $user->forceFill(['ii_research_role' => 'analyst'])->save();
        $this->actingAs($user);

        config()->set('ii.fred.report_rights.CPIAUCSL', [
            'status' => 'REVIEWED_ALLOWED',
            'scope' => 'COMMERCIAL_REDISTRIBUTION', // explicitly not authorized
            'receipt_sha256' => hash('sha256', 'fake-ci-receipt'),
            'source_owner' => 'U.S. Bureau of Labor Statistics',
        ]);
        $this->get('/ii/macro/report.csv')->assertForbidden();
        $this->assertDatabaseCount('ii_lineage_nodes', 0);

        config()->set('ii.fred.report_rights.CPIAUCSL.scope', 'LOCAL_PERSONAL_NONCOMMERCIAL_CSV');
        \App\Models\EconomicObservation::query()->where('series_id', 'CPIAUCSL')
            ->orderByDesc('observed_on')->firstOrFail()->update([
                'source_url' => 'https://fred.stlouisfed.org.evil.example/graph/fredgraph.csv',
            ]);
        Cache::flush();
        $this->get('/ii/macro/report/rights')->assertOk()
            ->assertJsonPath('issues.0', 'CPIAUCSL:UNTRUSTED_SOURCE_URL');
        $this->get('/ii/macro/report.csv')->assertForbidden();
        $this->assertDatabaseCount('ii_lineage_nodes', 0);
    }

    public function test_failed_source_is_recorded_without_observations(): void
    {
        Http::fake(['fred.stlouisfed.org/*' => Http::response('unavailable', 503)]);
        try {
            app(FredIngestionService::class)->ingest('FEDFUNDS');
            $this->fail('Expected ingestion failure.');
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('ii_ingestion_runs', ['series_id' => 'FEDFUNDS', 'status' => 'FAILED']);
            $this->assertDatabaseCount('ii_economic_observations', 0);
        }
    }

    private function cpiCsv(): string
    {
        return "observation_date,CPIAUCSL\n"
            ."2025-01-01,300\n2025-02-01,301\n2025-03-01,302\n2025-04-01,303\n"
            ."2025-05-01,304\n2025-06-01,305\n2025-07-01,306\n2025-08-01,307\n"
            ."2025-09-01,308\n2025-10-01,309\n2025-11-01,310\n2025-12-01,311\n"
            ."2026-01-01,315\n";
    }
}
