<?php

namespace App\Services;

/**
 * Single chronological research holdout, NOT a multi-fold walk-forward study.
 * Strategy selection uses only the first 70%; the last 30% stays untouched
 * until the winner is fixed. Every preset starts on the SAME training date.
 */
class StrategyValidationService
{
    private const PRESETS = [[10, 30], [20, 50]];
    private const COMMON_WARMUP = 50;

    public function __construct(private readonly StrategyReplayService $replay)
    {
    }

    public function evaluate(array $document, int $feeBps, int $slippageBps): array
    {
        if ($feeBps < 0 || $feeBps > 100 || $slippageBps < 0 || $slippageBps > 100) {
            throw new \InvalidArgumentException('Biaya simulasi harus antara 0 dan 100 bps per sisi.');
        }
        $rows = $document['rows'] ?? null;
        if (!is_array($rows) || count($rows) < 90) {
            throw new \InvalidArgumentException('Holdout membutuhkan minimal 90 sesi OHLCV. Strategy Lab R1 tetap dapat menggunakan 60 sesi.');
        }

        $total = count($rows);
        $split = (int) floor($total * 0.70);
        $trainingSessions = $split - self::COMMON_WARMUP;
        $testSessions = $total - $split;
        if ($trainingSessions < 10 || $testSessions < 20) {
            throw new \InvalidArgumentException('Sejarah belum cukup panjang untuk pemisahan kronologis yang tersedia.');
        }

        $candidates = [];
        foreach (self::PRESETS as [$fast, $slow]) {
            // Align both training windows to global index 50, not 30 vs 50.
            $training = $document;
            $training['rows'] = array_slice($rows, self::COMMON_WARMUP - $slow, $split - (self::COMMON_WARMUP - $slow));

            $train = $this->replay->replay($training, $fast, $slow, $feeBps, $slippageBps);
            $candidates[] = [
                'name' => 'SMA'.$fast.'/SMA'.$slow,
                'fast' => $fast,
                'slow' => $slow,
                'train' => $train,
            ];
        }

        // Sort solely on training excess return. No test statistic participates
        // in selecting a candidate; stable tie-break is by preset name.
        usort($candidates, static function (array $a, array $b): int {
            $difference = $b['train']['excess_return_pp'] <=> $a['train']['excess_return_pp'];
            return $difference !== 0 ? $difference : strcmp($a['name'], $b['name']);
        });

        // Selection is now fixed. Only now evaluate the held-out tail.
        // Historical closes before the cutoff are calculation context only;
        // both strategies independently start test with all cash.
        foreach ($candidates as &$candidate) {
            $testDocument = $document;
            $testDocument['rows'] = array_slice($rows, $split - $candidate['slow']);
            $candidate['test'] = $this->replay->replay($testDocument, $candidate['fast'], $candidate['slow'], $feeBps, $slippageBps);
        }
        unset($candidate);
        $chosen = $candidates[0];
        $heldOut = $document;
        $heldOut['rows'] = array_slice($rows, $split - $chosen['slow']);
        $stress = [];
        foreach ([
            ['name' => 'Tanpa biaya (optimistis)', 'fee' => 0, 'slip' => 0],
            ['name' => 'Asumsi pengguna', 'fee' => $feeBps, 'slip' => $slippageBps],
            ['name' => 'Stress biaya +25 bps/sisi', 'fee' => min(100, $feeBps + 25), 'slip' => min(100, $slippageBps + 25)],
        ] as $scenario) {
            $result = $this->replay->replay($heldOut, $chosen['fast'], $chosen['slow'], $scenario['fee'], $scenario['slip']);
            $stress[] = [
                'name' => $scenario['name'],
                'fee_bps' => $scenario['fee'],
                'slippage_bps' => $scenario['slip'],
                'strategy_return_pct' => $result['strategy_return_pct'],
                'buy_hold_return_pct' => $result['buy_hold_return_pct'],
                'excess_return_pp' => $result['excess_return_pp'],
            ];
        }

        return [
            'ticker' => $document['ticker'],
            'kind' => $document['kind'],
            'source_name' => $document['source_name'],
            'imported_at' => $document['imported_at'],
            'total_sessions' => $total,
            'train_sessions' => $trainingSessions,
            'test_sessions' => $testSessions,
            'train_start' => $rows[self::COMMON_WARMUP]['date'],
            'train_end' => $rows[$split - 1]['date'],
            'test_start' => $rows[$split]['date'],
            'test_end' => $rows[$total - 1]['date'],
            'fee_bps' => $feeBps,
            'slippage_bps' => $slippageBps,
            'candidates' => $candidates,
            'chosen' => $chosen,
            'stress' => $stress,
            'weak_sample' => $trainingSessions < 60 || $testSessions < 40,
            'status' => $chosen['test']['excess_return_pp'] > 0 ? 'OOS_ABOVE_BENCHMARK' : 'OOS_NOT_ABOVE_BENCHMARK',
            'evidence' => 'USER_IMPORTED_UNVERIFIED',
            'decision' => 'HOLD',
            'trading_authorized' => false,
        ];
    }
}
