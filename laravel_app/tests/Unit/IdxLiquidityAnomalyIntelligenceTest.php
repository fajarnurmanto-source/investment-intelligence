<?php

namespace Tests\Unit;

use App\Services\IdxLiquidityAnomalyIntelligence;
use Tests\TestCase;

final class IdxLiquidityAnomalyIntelligenceTest extends TestCase
{
    private function sessions(int $n): array
    {
        $sessions=[];
        for ($i=0;$i<$n;$i++) {
            $date=(new \DateTimeImmutable('2024-01-01'))->modify("+$i days")->format('d M Y');
            $rows=[];
            foreach (['BBCA','BBRI','BMRI'] as $ticker) {
                $volume=$ticker==='BBCA' ? ($i===20 ? 300 : ($i%2 ? 110 : 90)) : 100;
                $rows[]=['Stock Code'=>$ticker,'Last Trading Date'=>$date,'Previous'=>'100',
                    'Open Price'=>'100','High'=>'110','Low'=>'90','Close'=>'100',
                    'Volume'=>$ticker==='BMRI' && $i===3 ? '0' : (string)$volume,
                    'Value'=>$ticker==='BBCA' ? (string)($volume*100) : '',
                    'Frequency'=>$ticker==='BBCA' ? (string)($i===20 ? 1 : ($i%2 ? 110 : 90)) : '10'];
            }
            $sessions[]=['rows'=>$rows,'sha256'=>hash('sha256',$date)];
        }
        return $sessions;
    }

    public function test_robust_activity_baseline_excludes_latest_and_abstains_on_zero_mad(): void
    {
        $engine=app(IdxLiquidityAnomalyIntelligence::class);
        $sessions=$this->sessions(21);
        $r=$engine->analyze($sessions);
        $this->assertSame($r,$engine->analyze(array_reverse($sessions)));
        $this->assertSame('OBSERVED_ONLY',$r['status']);
        $this->assertSame(1,$r['coverage']['volume']['scored']);
        $this->assertSame(1,$r['coverage']['volume']['zero_mad']);
        $this->assertSame(1,$r['coverage']['volume']['insufficient']);
        $v=$r['anomalies']['volume'][0];
        $this->assertSame('BBCA',$v['ticker']);
        $this->assertEqualsWithDelta(100,$v['baseline_median'],1e-9);
        $this->assertEqualsWithDelta(10,$v['baseline_mad'],1e-9);
        $this->assertEqualsWithDelta(3,$v['ratio'],1e-9);
        $this->assertEqualsWithDelta(13.489795003921634,$v['robust_z'],1e-9);
        $this->assertLessThan(-3.5,$r['anomalies']['frequency'][0]['robust_z']);
        $this->assertFalse($r['admitted']);
        $this->assertFalse($r['trading_authorized']);
    }

    public function test_short_and_empty_history_never_generate_anomaly_scores(): void
    {
        $engine=app(IdxLiquidityAnomalyIntelligence::class);
        foreach ([0,5,20] as $n) {
            $r=$engine->analyze($this->sessions($n));
            $this->assertSame('INSUFFICIENT_HISTORY',$r['status']);
            $this->assertSame($n,$r['available']);
            $this->assertSame(21,$r['required']);
            foreach ($r['anomalies'] as $group) {
                $this->assertSame([],$group);
            }
        }
    }

    public function test_duplicate_dates_are_rejected(): void
    {
        $s=$this->sessions(1)[0];
        $this->expectExceptionMessage('II_IDX_ACTIVITY_DATE_INVALID');
        app(IdxLiquidityAnomalyIntelligence::class)->analyze([$s,$s]);
    }
}
