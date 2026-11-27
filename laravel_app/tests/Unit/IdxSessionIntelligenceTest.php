<?php

namespace Tests\Unit;

use App\Services\IdxSessionIntelligence;
use PHPUnit\Framework\TestCase;

final class IdxSessionIntelligenceTest extends TestCase
{
    private function row(string $ticker, array $overrides = []): array
    {
        return array_replace([
            'Stock Code'=>$ticker,'Last Trading Date'=>'27 Nov 2026',
            'Previous'=>'100','Open Price'=>'100','High'=>'120','Low'=>'90',
            'Close'=>'110','Volume'=>'1000','Value'=>'110000','Frequency'=>'20',
            'Foreign Buy'=>'300','Foreign Sell'=>'100',
        ], $overrides);
    }

    public function test_breadth_and_raw_rankings_preserve_missingness(): void
    {
        $out = (new IdxSessionIntelligence())->summarize([
            $this->row('BBCA'),
            $this->row('BBRI',['Close'=>'90','Foreign Buy'=>'10','Foreign Sell'=>'50']),
            $this->row('BMRI',['Close'=>'100','Foreign Buy'=>'']),
            $this->row('TLKM',['Open Price'=>'0','Volume'=>'0']),
            $this->row('GOTO',['Volume'=>'','Value'=>'']),
        ]);
        $this->assertSame(5,$out['universe']);
        $this->assertSame(1,$out['breadth']['up']);
        $this->assertSame(1,$out['breadth']['down']);
        $this->assertSame(1,$out['breadth']['flat']);
        $this->assertSame(2,$out['breadth']['excluded']);
        $this->assertSame(1,$out['breadth']['zero_open']);
        $this->assertSame(1,$out['breadth']['zero_volume']);
        $this->assertSame(1,$out['breadth']['missing_volume']);
        $this->assertSame(4,$out['foreign_field_coverage']);
        $this->assertEquals(560,$out['foreign_raw_net_sum']);
        $this->assertSame('BBCA',$out['top_price_moves'][0]['ticker']);
        $this->assertEquals(10,$out['top_price_moves'][0]['close_vs_previous_pct']);
        $this->assertSame('BBRI',$out['bottom_price_moves'][0]['ticker']);
        $this->assertSame(4,$out['liquidity_field_coverage']);
        $this->assertFalse($out['admitted']);
        $this->assertFalse($out['trading_authorized']);
    }

    public function test_duplicate_identity_fails_closed(): void
    {
        $this->expectExceptionMessage('II_IDX_INTELLIGENCE_IDENTITY_INVALID');
        (new IdxSessionIntelligence())->summarize([$this->row('BBCA'),$this->row('BBCA')]);
    }

    public function test_mixed_sessions_fail_closed(): void
    {
        $this->expectExceptionMessage('II_IDX_INTELLIGENCE_IDENTITY_INVALID');
        (new IdxSessionIntelligence())->summarize([
            $this->row('BBCA'),$this->row('BBRI',['Last Trading Date'=>'26 Nov 2026']),
        ]);
    }

    public function test_invalid_range_and_missing_previous_are_excluded(): void
    {
        $out = (new IdxSessionIntelligence())->summarize([
            $this->row('BBCA',['High'=>'99']),
            $this->row('BBRI',['Previous'=>'']),
        ]);
        $this->assertSame(2,$out['breadth']['excluded']);
        $this->assertSame(1,$out['breadth']['invalid_ohlc']);
        $this->assertSame([],$out['top_price_moves']);
    }
}
