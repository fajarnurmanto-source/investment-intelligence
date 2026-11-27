<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ResearchContinuityService;
use App\Services\SignalInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ResearchContinuityTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_archive_and_corrupt_newer_version_isolation(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();
        $owner = User::factory()->create();
        $owner->forceFill(['ii_research_role' => 'analyst'])->save();
        $other = User::factory()->create();
        $other->forceFill(['ii_research_role' => 'analyst'])->save();
        $rows = [];
        $start = new \DateTimeImmutable('2024-01-01');
        for ($i = 0; $i < 60; $i++) {
            $rows[] = ['date' => $start->modify('+'.$i.' days')->format('Y-m-d'),
                'open' => 100, 'high' => 102, 'low' => 98, 'close' => 101, 'volume' => 1000];
        }
        $doc = ['ticker' => 'BBCA', 'kind' => 'user_supplied', 'source_name' => 'Synthetic test fixture',
            'imported_at' => '2024-04-01', 'acquisition_mode' => 'PINNED_HISTORICAL_SNAPSHOT', 'rows' => $rows];
        $signals = app(SignalInsightService::class);
        $signals->save($owner->id, 'BBCA', $doc);
        $bad = $doc;
        $bad['imported_at'] = '2024-04-02';
        $bad['rows'][59]['close'] = 0;
        $signals->save($owner->id, 'BBCA', $bad);
        $tampered = $doc;
        $tampered['imported_at'] = '2024-04-03';
        Storage::disk('local')->put('ii_signal_center/u'.$owner->id.'/unregistered.json', json_encode($tampered));
        $this->assertSame('2024-04-01', $signals->latestDocument($owner->id, 'BBCA')['imported_at']);
        $overview = app(ResearchContinuityService::class)->overview($owner->id);
        $this->assertSame('LOCAL_RESEARCH_AVAILABLE', $overview['mode']);
        $this->assertSame(1, $overview['registered']);
        $this->assertSame(0, $overview['recent']);
        $this->assertSame('NOT_PROBED', $overview['live_provider_status']);
        $this->assertSame('NO_LOCAL_RESEARCH_DATA', app(ResearchContinuityService::class)->overview($other->id)['mode']);
        $this->actingAs($owner)->get('/ii/market-data')->assertOk()->assertSee('LOCAL_RESEARCH_AVAILABLE')->assertSee('BBCA');
        $this->actingAs($other)->get('/ii/market-data')->assertOk()->assertSee('NO_LOCAL_RESEARCH_DATA')->assertDontSee('BBCA');
        Http::assertNothingSent();
    }
}
