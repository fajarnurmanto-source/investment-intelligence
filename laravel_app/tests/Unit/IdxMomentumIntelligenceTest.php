<?php

namespace Tests\Unit;

use App\Services\IdxMomentumIntelligence;
use Tests\TestCase;

final class IdxMomentumIntelligenceTest extends TestCase
{
    private function sessions(int $n): array
    {
        $sessions = [];
        for ($i=0; $i<$n; $i++) {
            $date = (new \DateTimeImmutable('2024-01-01'))->modify("+$i days")->format('d M Y');
            $rows = [];
            foreach (['BBCA'=>100+$i*10,'BBRI'=>100,'BMRI'=>100] as $ticker=>$close) {
                $rows[] = ['Stock Code'=>$ticker,'Last Trading Date'=>$date,'Previous'=>'100',
                    'Open Price'=>'100','High'=>(string)max(100,$close),'Low'=>'100','Close'=>(string)$close,
                    'Volume'=>$ticker==='BMRI' && $i===2 ? '0' : ($i===4 ? '300' : '100')];
            }
            $sessions[] = ['rows'=>$rows,'sha256'=>hash('sha256',$date)];
        }
        return $sessions;
    }

    public function test_five_observation_math_and_longer_window_abstention(): void
    {
        $engine = app(IdxMomentumIntelligence::class);
        $sessions = $this->sessions(5);
        $r = $engine->analyze(array_reverse($sessions));
        $this->assertSame($r,$engine->analyze($sessions));
        $w = $r['windows'][0];
        $this->assertSame('OBSERVED_ONLY',$w['status']);
        $this->assertSame(2,$w['matched_count']);
        $this->assertEqualsWithDelta(20,$w['median_change_pct'],1e-9);
        $this->assertSame('BBCA',$w['leaders'][0]['ticker']);
        $this->assertEqualsWithDelta(40,$w['leaders'][0]['price_change_pct'],1e-9);
        $this->assertEqualsWithDelta(20,$w['leaders'][0]['relative_to_median_pp'],1e-9);
        $this->assertEqualsWithDelta(100,$w['leaders'][0]['up_transition_pct'],1e-9);
        $this->assertEqualsWithDelta(3,$w['leaders'][0]['raw_volume_ratio'],1e-9);
        $this->assertEqualsWithDelta(-5/3,$w['leaders'][0]['acceleration_pp_per_transition'],1e-9);
        foreach (array_slice($r['windows'],1) as $window) {
            $this->assertSame('INSUFFICIENT_HISTORY',$window['status']);
            $this->assertArrayNotHasKey('median_change_pct',$window);
        }
        $this->assertFalse($r['calendar_verified']);
        $this->assertFalse($r['admitted']);
        $this->assertFalse($r['trading_authorized']);
    }

    public function test_full_bounded_history_and_empty_history(): void
    {
        $engine = app(IdxMomentumIntelligence::class);
        $r = $engine->analyze($this->sessions(250));
        $this->assertSame([5,20,60,120,250],array_column($r['windows'],'window'));
        $this->assertSame(array_fill(0,5,'OBSERVED_ONLY'),array_column($r['windows'],'status'));
        $this->assertSame(250,$r['available']);
        $this->assertSame(2,$r['windows'][4]['matched_count']);
        $this->assertSame(3,$r['windows'][0]['matched_count']);
        $this->assertSame(array_fill(0,5,'INSUFFICIENT_HISTORY'),array_column($engine->analyze([])['windows'],'status'));
    }

    public function test_duplicate_dates_fail_closed(): void
    {
        $session = $this->sessions(1)[0];
        $this->expectExceptionMessage('II_IDX_MOMENTUM_DATE_INVALID');
        app(IdxMomentumIntelligence::class)->analyze([$session,$session]);
    }
}
