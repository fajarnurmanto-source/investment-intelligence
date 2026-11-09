<?php

namespace Tests\Unit;

use App\Services\FundamentalScreenerService;
use App\Services\PortfolioScenarioService;
use PHPUnit\Framework\TestCase;

class BenchmarkCapabilitiesTest extends TestCase
{
    public function test_screener_filters_and_ranks_transparently(): void
    {
        $records = [
            'GOOD' => $this->fundamental('GOOD', 20, 12, 18, true),
            'WEAK' => $this->fundamental('WEAK', 2, -4, 3, false),
        ];
        $rows = (new FundamentalScreenerService())->screen($records, [
            'min_margin' => 10, 'min_growth' => 0, 'min_roe' => 10,
            'cash_positive' => true, 'sort' => 'quality_score',
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame('GOOD', $rows[0]['ticker']);
        $this->assertGreaterThan(70, $rows[0]['quality_score']);
    }

    public function test_portfolio_includes_mathphp_tail_risk(): void
    {
        $documents = [
            'AAA' => $this->priceDocument('AAA', 100, 0.5),
            'BBB' => $this->priceDocument('BBB', 90, -0.15),
        ];
        $result = (new PortfolioScenarioService())->analyze($documents, ['AAA' => 60.0, 'BBB' => 40.0]);
        $this->assertArrayHasKey('historical_var_95_pct', $result);
        $this->assertArrayHasKey('historical_cvar_95_pct', $result);
        $this->assertNotNull($result['historical_var_95_pct']);
        $this->assertCount(1, $result['pairwise_correlations']);
    }

    private function fundamental(string $ticker, float $margin, float $growth, float $roe, bool $cash): array
    {
        return ['ticker' => $ticker, 'net_margin_pct' => $margin, 'revenue_growth_pct' => $growth,
            'roe_end_pct' => $roe, 'income_positive' => $margin > 0, 'cash_flow_positive' => $cash];
    }

    private function priceDocument(string $ticker, float $base, float $drift): array
    {
        $rows = [];
        $date = new \DateTimeImmutable('2026-01-01');
        for ($i = 0; $i < 80; $i++) {
            $shock = ($i % 17 === 0) ? -4.0 : (($i % 11 === 0) ? 2.2 : 0.0);
            $price = max(10, $base + $i * $drift + $shock);
            $rows[] = ['date' => $date->modify('+'.$i.' days')->format('Y-m-d'), 'close' => $price];
        }
        return ['ticker' => $ticker, 'kind' => 'synthetic', 'source_name' => 'test', 'imported_at' => '2026-11-07',
            'currency' => 'USD', 'price_adjustment' => 'UNADJUSTED', 'session_timezone' => 'UTC', 'rows' => $rows];
    }
}
