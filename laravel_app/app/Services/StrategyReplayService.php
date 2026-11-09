<?php

namespace App\Services;

/**
 * Deterministic historical research replay (long/cash, no leverage or shorts).
 * The signal uses closes available THROUGH i-1; it executes on open i.
 * Never generates a present-day BUY/SELL instruction or brokerage order.
 */
class StrategyReplayService
{
    private function average(array $close, int $endExclusive, int $lookback): float
    {
        return array_sum(array_slice($close, $endExclusive - $lookback, $lookback)) / $lookback;
    }

    public function replay(array $document, int $fast, int $slow, int $feeBps, int $slippageBps): array
    {
        if (!in_array([$fast, $slow], [[10, 30], [20, 50]], true)
            || $feeBps < 0 || $feeBps > 100 || $slippageBps < 0 || $slippageBps > 100) {
            throw new \InvalidArgumentException('Strategy settings are outside the approved research range.');
        }
        $rows = $document['rows'] ?? [];
        if (!is_array($rows) || count($rows) < $slow + 10) {
            throw new \InvalidArgumentException('OHLCV history is too short for this strategy.');
        }
        $close = array_map(fn (array $r) => (float) $r['close'], $rows);
        $capital = 1000000.0;
        $cash = $capital;
        $units = 0.0;
        $benchmarkUnits = 0.0;
        $fee = $feeBps / 10000.0;
        $slip = $slippageBps / 10000.0;
        $entrySpend = null;
        $trades = [];
        $closedCount = 0;
        $winners = 0;
        $curve = [];
        $peak = $capital;
        $maxDrawdown = 0.0;

        for ($i = $slow; $i < count($rows); $i++) {
            $day = $rows[$i];
            $open = (float) $day['open'];
            $last = (float) $day['close'];
            if (!is_finite($open) || !is_finite($last) || $open <= 0 || $last <= 0) {
                throw new \InvalidArgumentException('Price data contains invalid observations.');
            }
            $decision = $this->average($close, $i, $fast) > $this->average($close, $i, $slow);
            if ($i === $slow) {
                $benchmarkUnits = $capital / ($open * (1 + $slip) * (1 + $fee));
            }

            if ($decision && $units <= 0) {
                $execution = $open * (1 + $slip);
                $units = $cash / ($execution * (1 + $fee));
                $entrySpend = $cash;
                $cash = 0.0;
                $trades[] = ['date' => $day['date'], 'side' => 'SIM BUY', 'price' => round($execution, 4), 'reason' => 'SMA fast above SMA slow at previous close'];
            } elseif (!$decision && $units > 0) {
                $execution = $open * (1 - $slip);
                $cash = $units * $execution * (1 - $fee);
                $closedCount++;
                if ($entrySpend !== null && $cash > $entrySpend) {
                    $winners++;
                }
                $trades[] = ['date' => $day['date'], 'side' => 'SIM EXIT', 'price' => round($execution, 4), 'reason' => 'SMA fast no longer above SMA slow'];
                $entrySpend = null;
                $units = 0.0;
            }

            $value = $cash + $units * $last;
            $benchmarkValue = $benchmarkUnits * $last;
            $peak = max($peak, $value);
            $drawdown = 100 * ($peak - $value) / $peak;
            $maxDrawdown = max($maxDrawdown, $drawdown);
            $curve[] = [
                'date' => $day['date'],
                'strategy' => round($value, 2),
                'buy_hold' => round($benchmarkValue, 2),
            ];
        }

        $final = $curve[count($curve) - 1];
        $strategyReturn = ($final['strategy'] / $capital - 1) * 100;
        $benchmarkReturn = ($final['buy_hold'] / $capital - 1) * 100;

        // Shared Y-scale for an honest chart comparison.
        $allValues = [];
        foreach ($curve as $row) {
            $allValues[] = $row['strategy'];
            $allValues[] = $row['buy_hold'];
        }
        $lo = min($allValues);
        $range = max(max($allValues) - $lo, 0.000001);
        $strategyPoints = [];
        $benchmarkPoints = [];
        $n = count($curve);
        foreach ($curve as $i => $row) {
            $x = round(10 + 680 * $i / max($n - 1, 1), 1);
            $strategyPoints[] = $x.','.round(140 - 120 * ($row['strategy'] - $lo) / $range, 1);
            $benchmarkPoints[] = $x.','.round(140 - 120 * ($row['buy_hold'] - $lo) / $range, 1);
        }

        return [
            'ticker' => $document['ticker'],
            'kind' => $document['kind'],
            'source_name' => $document['source_name'],
            'source_url' => $document['source_url'] ?? null,
            'last_imported' => $document['imported_at'],
            'fast' => $fast, 'slow' => $slow,
            'fee_bps' => $feeBps, 'slippage_bps' => $slippageBps,
            'period_start' => $curve[0]['date'],
            'period_end' => $final['date'],
            'observations' => count($curve),
            'starting_capital' => $capital,
            'strategy_ending' => $final['strategy'],
            'buy_hold_ending' => $final['buy_hold'],
            'strategy_return_pct' => round($strategyReturn, 2),
            'buy_hold_return_pct' => round($benchmarkReturn, 2),
            'excess_return_pp' => round($strategyReturn - $benchmarkReturn, 2),
            'max_drawdown_pct' => round($maxDrawdown, 2),
            'trade_orders' => count($trades),
            'closed_trades' => $closedCount,
            'win_rate_pct' => $closedCount > 0 ? round(100 * $winners / $closedCount, 2) : null,
            'open_position_at_end' => $units > 0,
            'trades' => array_reverse(array_slice($trades, -15)),
            'chart_strategy' => implode(' ', $strategyPoints),
            'chart_buy_hold' => implode(' ', $benchmarkPoints),
            'evidence' => 'USER_IMPORTED_UNVERIFIED',
            'decision' => 'HOLD',
            'trading_authorized' => false,
        ];
    }
}
