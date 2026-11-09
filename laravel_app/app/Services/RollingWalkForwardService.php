<?php

namespace App\Services;

/**
 * Rolling-origin historical research. Training is expanded at each fold;
 * strategy selection depends on past closes only. Test blocks do not overlap.
 * No live signals, broker access, or trading authority.
 */
class RollingWalkForwardService
{
    private const PRESETS = [[10, 30], [20, 50]];
    private const WARMUP = 50;
    private const TEST_SESSIONS = 30;
    private const MIN_TRAIN_BOUNDARY = 90;

    public function __construct(private readonly StrategyReplayService $replay)
    {
    }

    /** 260 deterministic synthetic weekday observations; not market prices. */
    public function demoRows(\DateTimeImmutable $today): array
    {
        $first = $today->modify('-365 days');
        $rows = [];
        $price = 100.0;
        for ($i = 0; $i <= 365 && count($rows) < 260; $i++) {
            $day = $first->modify('+'.$i.' days');
            if ((int) $day->format('N') > 5) { continue; }
            $index = count($rows);
            $drift = $index < 85 ? 0.0023 : ($index < 170 ? -0.0018 : 0.0020);
            $change = $drift + sin($index * 0.31) * 0.009;
            $open = $price;
            $price = round(max(1.0, $open * (1 + $change)), 4);
            $rows[] = [
                'date' => $day->format('Y-m-d'),
                'open' => $open,
                'high' => round(max($open, $price) * 1.008, 4),
                'low' => round(min($open, $price) * 0.992, 4),
                'close' => $price,
                'volume' => 10000 + $index * 13,
            ];
        }
        return $rows;
    }

    public function evaluate(array $document, int $feeBps, int $slippageBps): array
    {
        if ($feeBps < 0 || $feeBps > 100 || $slippageBps < 0 || $slippageBps > 100) {
            throw new \InvalidArgumentException('Fee/slippage harus berada pada rentang 0–100 bps/sisi.');
        }
        $rows = $document['rows'] ?? null;
        $n = is_array($rows) ? count($rows) : 0;
        $foldCount = min(4, intdiv(max(0, $n - self::MIN_TRAIN_BOUNDARY), self::TEST_SESSIONS));
        if ($foldCount < 2) {
            throw new \InvalidArgumentException('Rolling validation memerlukan minimal 150 sesi. Data yang lebih pendek tetap dapat diteliti di Strategy Validation VS8 R2.');
        }

        $firstTestStart = $n - $foldCount * self::TEST_SESSIONS;
        $exitFactor = (1 - $feeBps / 10000.0) * (1 - $slippageBps / 10000.0);
        $folds = [];
        $strategyGrowth = 1.0;
        $benchmarkGrowth = 1.0;
        $positiveFolds = 0;
        $worstFoldDrawdown = 0.0;
        $selectedCounts = ['SMA10/SMA30' => 0, 'SMA20/SMA50' => 0];

        for ($foldNumber = 0; $foldNumber < $foldCount; $foldNumber++) {
            $testStart = $firstTestStart + $foldNumber * self::TEST_SESSIONS;
            $testEnd = $testStart + self::TEST_SESSIONS;
            $candidates = [];

            foreach (self::PRESETS as [$fast, $slow]) {
                // Common training start at global index 50 for both presets.
                $training = $document;
                $training['rows'] = array_slice($rows, self::WARMUP - $slow, $testStart - (self::WARMUP - $slow));
                $train = $this->replay->replay($training, $fast, $slow, $feeBps, $slippageBps);
                $candidates[] = [
                    'name' => 'SMA'.$fast.'/SMA'.$slow,
                    'fast' => $fast,
                    'slow' => $slow,
                    'training_excess_pp' => $train['excess_return_pp'],
                    'training_return_pct' => $train['strategy_return_pct'],
                ];
            }
            // Fix selection BEFORE looking at the next 30 test sessions.
            usort($candidates, static function (array $a, array $b): int {
                $comp = $b['training_excess_pp'] <=> $a['training_excess_pp'];
                return $comp !== 0 ? $comp : strcmp($a['name'], $b['name']);
            });
            $selected = $candidates[0];

            // Previous closes for SMA are context only; test starts from cash.
            $holdout = $document;
            $holdout['rows'] = array_slice($rows, $testStart - $selected['slow'], $testEnd - ($testStart - $selected['slow']));
            $test = $this->replay->replay($holdout, $selected['fast'], $selected['slow'], $feeBps, $slippageBps);

            // Research-only hypothetical liquidation at final test CLOSE.
            // R1 leaves positions at mark-to-market, so charge one extra
            // exit side for positions still open before chaining folds.
            $netStrategyEnd = $test['strategy_ending'] * ($test['open_position_at_end'] ? $exitFactor : 1.0);
            $netBuyHoldEnd = $test['buy_hold_ending'] * $exitFactor;
            $strategyFactor = $netStrategyEnd / $test['starting_capital'];
            $benchmarkFactor = $netBuyHoldEnd / $test['starting_capital'];
            $strategyGrowth *= $strategyFactor;
            $benchmarkGrowth *= $benchmarkFactor;
            $excessPp = ($strategyFactor - $benchmarkFactor) * 100;
            if ($excessPp > 0) { $positiveFolds++; }
            $worstFoldDrawdown = max($worstFoldDrawdown, $test['max_drawdown_pct']);
            $selectedCounts[$selected['name']]++;

            $folds[] = [
                'number' => $foldNumber + 1,
                'training_start' => $rows[self::WARMUP]['date'],
                'training_end' => $rows[$testStart - 1]['date'],
                'training_sessions' => $testStart - self::WARMUP,
                'test_start' => $rows[$testStart]['date'],
                'test_end' => $rows[$testEnd - 1]['date'],
                'test_sessions' => self::TEST_SESSIONS,
                'selection' => $selected['name'],
                'training_excess_pp' => $selected['training_excess_pp'],
                'strategy_return_pct' => round(($strategyFactor - 1) * 100, 2),
                'benchmark_return_pct' => round(($benchmarkFactor - 1) * 100, 2),
                'excess_pp' => round($excessPp, 2),
                'drawdown_pct_before_exit' => $test['max_drawdown_pct'],
                'simulation_orders' => $test['trade_orders'],
                'final_liquidation_charged' => $test['open_position_at_end'],
            ];
        }

        return [
            'ticker' => $document['ticker'],
            'kind' => $document['kind'],
            'source_name' => $document['source_name'],
            'imported_at' => $document['imported_at'],
            'history_sessions' => $n,
            'fold_count' => $foldCount,
            'folds' => $folds,
            'test_sessions_total' => $foldCount * self::TEST_SESSIONS,
            'first_test_date' => $rows[$firstTestStart]['date'],
            'last_test_date' => $rows[$n - 1]['date'],
            'strategy_growth_pct' => round(($strategyGrowth - 1) * 100, 2),
            'benchmark_growth_pct' => round(($benchmarkGrowth - 1) * 100, 2),
            'aggregate_excess_pp' => round(($strategyGrowth - $benchmarkGrowth) * 100, 2),
            'positive_folds' => $positiveFolds,
            'worst_fold_intraperiod_drawdown_pct' => $worstFoldDrawdown,
            'selected_counts' => $selectedCounts,
            'fee_bps' => $feeBps,
            'slippage_bps' => $slippageBps,
            'weak_sample' => $folds[0]['training_sessions'] < 80 || $foldCount < 3,
            'evidence' => 'USER_IMPORTED_UNVERIFIED',
            'decision' => 'HOLD',
            'trading_authorized' => false,
        ];
    }
}
