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
        $this->actingAs($user)->get('/ii/macro/report.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
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
