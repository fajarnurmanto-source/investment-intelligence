<?php

namespace Tests\Feature;

use App\Services\MarketHistoryPlanner;
use Tests\TestCase;

final class MarketHistoryPlannerTest extends TestCase
{
    public function test_bootstrap_full_incremental_gap_and_conflict(): void
    {
        $cfg = ['provider_symbol' => 'AAA', 'provider_mic' => 'XNAS',
            'source_name' => 'Twelve Data', 'source_url' => 'https://api.twelvedata.com/time_series',
            'rights_reference' => 'test', 'currency' => 'USD', 'price_adjustment' => 'unadjusted',
            'session_timezone' => 'America/New_York', 'history_sessions' => 60];
        $planner = app(MarketHistoryPlanner::class);
        $this->assertSame('BOOTSTRAP', $planner->plan($cfg, null)['action']);
        $doc = $cfg + ['provider_identity' => 'AAA@XNAS', 'acquisition_mode' => 'LICENSED_PROVIDER_API',
            'rows' => array_map(fn ($i) => ['date' => now('America/New_York')->subDays(100 - $i)->toDateString()], range(0, 59))];
        $this->assertSame('INCREMENTAL', $planner->plan($cfg, $doc)['action']);
        $this->assertSame($doc['rows'][59]['date'], $planner->plan($cfg, $doc)['checkpoint']);
        $this->assertSame('FULL_WINDOW', $planner->plan(array_replace($cfg, ['history_sessions' => 250]), $doc)['action']);
        $gap = $doc;
        $gap['rows'][59]['date'] = now('America/New_York')->subDay()->toDateString();
        $this->assertSame('FULL_WINDOW_GAP_REVIEW', $planner->plan($cfg, $gap)['action']);
        $this->assertSame('BLOCK_METADATA_CONFLICT', $planner->plan($cfg, array_replace($doc, ['currency' => 'IDR']))['action']);
        $this->assertSame('BLOCK_SOURCE_CONFLICT', $planner->plan($cfg, array_replace($doc, ['provider_identity' => 'AAA@XNYS']))['action']);
        $invalid = $doc;
        $invalid['rows'][59] = $invalid['rows'][58];
        $this->assertSame('BLOCK_INVALID_HISTORY', $planner->plan($cfg, $invalid)['action']);
    }
}
