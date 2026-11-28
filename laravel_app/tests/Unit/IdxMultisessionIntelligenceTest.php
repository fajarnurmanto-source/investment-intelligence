<?php

namespace Tests\Unit;

use App\Services\IdxMultisessionIntelligence;
use Tests\TestCase;

class IdxMultisessionIntelligenceTest extends TestCase
{
    private function row(string $ticker, string $date, string $close, string $volume): array
    {
        return ['Stock Code' => $ticker, 'Last Trading Date' => $date, 'Open Price' => '100',
            'High' => '200', 'Low' => '50', 'Close' => $close, 'Previous' => '100', 'Volume' => $volume];
    }

    public function test_chronological_comparison_uses_only_eligible_matched_instruments(): void
    {
        $first = ['rows' => [
            $this->row('BBCA', '01 Nov 2025', '100', '100'),
            $this->row('BBRI', '01 Nov 2025', '100', '200'),
            $this->row('BMRI', '01 Nov 2025', '100', '100'),
        ]];
        $last = ['rows' => [
            $this->row('BBCA', '03 Nov 2025', '120', '300'),
            $this->row('BBRI', '03 Nov 2025', '90', '100'),
            $this->row('BMRI', '03 Nov 2025', '110', '0'),
        ]];
        $r = app(IdxMultisessionIntelligence::class)->compare([$last, $first]);
        $this->assertSame('2025-11-01', $r['sessions'][0]['date']);
        $this->assertSame(2, $r['matched_count']);
        $this->assertEqualsWithDelta(5, $r['median_change_pct'], 0.00001);
        $this->assertSame('BBCA', $r['leaders'][0]['ticker']);
        $this->assertEqualsWithDelta(15, $r['leaders'][0]['relative_to_matched_median_pp'], 0.00001);
        $this->assertEqualsWithDelta(3, $r['activity'][0]['raw_volume_ratio'], 0.00001);
        $this->assertFalse($r['admitted']);
        $this->assertFalse($r['trading_authorized']);
    }

    public function test_duplicate_dates_are_rejected(): void
    {
        $session = ['rows' => [$this->row('BBCA', '01 Nov 2025', '100', '100')]];
        $this->expectException(\InvalidArgumentException::class);
        app(IdxMultisessionIntelligence::class)->compare([$session, $session]);
    }

    public function test_disjoint_cohort_does_not_invent_statistics(): void
    {
        $r = app(IdxMultisessionIntelligence::class)->compare([
            ['rows' => [$this->row('BBCA', '01 Nov 2025', '100', '100')]],
            ['rows' => [$this->row('BBRI', '03 Nov 2025', '100', '100')]],
        ]);
        $this->assertSame(0, $r['matched_count']);
        $this->assertNull($r['median_change_pct']);
        $this->assertSame([], $r['leaders']);
    }
}
