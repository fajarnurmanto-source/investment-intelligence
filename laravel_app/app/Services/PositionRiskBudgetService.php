<?php

namespace App\Services;

/**
 * Hypothetical long-only sizing calculator. Does NOT issue orders or a
 * recommendation. Stop execution is not guaranteed; loss can exceed budget.
 */
class PositionRiskBudgetService
{
    public function estimate(
        array $document,
        float $equity,
        float $riskPct,
        float $exposurePct,
        float $atrMultiple,
        float $rewardMultiple,
        int $feeBps,
        int $slippageBps,
    ): array {
        if (!is_finite($equity) || $equity < 1000 || $equity > 10000000000
            || !is_finite($riskPct) || $riskPct < 0.1 || $riskPct > 3
            || !is_finite($exposurePct) || $exposurePct < 5 || $exposurePct > 100
            || !is_finite($atrMultiple) || $atrMultiple < 1 || $atrMultiple > 5
            || !is_finite($rewardMultiple) || $rewardMultiple < 1 || $rewardMultiple > 5
            || $feeBps < 0 || $feeBps > 100 || $slippageBps < 0 || $slippageBps > 100) {
            throw new \InvalidArgumentException('Parameter berada di luar rentang simulasi yang diizinkan.');
        }
        $rows = $document['rows'] ?? null;
        if (!is_array($rows) || count($rows) < 60) {
            throw new \InvalidArgumentException('Minimal 60 sesi OHLCV diperlukan untuk simulasi risiko.');
        }
        $total = count($rows);
        $latest = $rows[$total - 1];
        $reference = (float) $latest['close'];
        if (!is_finite($reference) || $reference <= 0) {
            throw new \InvalidArgumentException('Harga penutupan terakhir tidak valid.');
        }
        $trSum = 0.0;
        for ($i = $total - 14; $i < $total; $i++) {
            $high = (float) $rows[$i]['high'];
            $low = (float) $rows[$i]['low'];
            $prev = (float) $rows[$i - 1]['close'];
            if (!is_finite($high) || !is_finite($low) || !is_finite($prev) || $low <= 0 || $high < $low || $prev <= 0) {
                throw new \InvalidArgumentException('Sejarah OHLCV mengandung harga tidak valid.');
            }
            $trSum += max($high - $low, abs($high - $prev), abs($low - $prev));
        }
        $atr = $trSum / 14.0;
        $stopDistance = $atr * $atrMultiple;
        $stopReference = $reference - $stopDistance;
        if (!is_finite($atr) || $atr <= 0 || !is_finite($stopReference) || $stopReference <= 0) {
            throw new \InvalidArgumentException('ATR atau stop hipotetis tidak valid untuk parameter ini.');
        }
        $targetReference = $reference + $stopDistance * $rewardMultiple;
        $fee = $feeBps / 10000.0;
        $slip = $slippageBps / 10000.0;
        $entryTotalPerUnit = $reference * (1 + $slip) * (1 + $fee);
        $stopNetPerUnit = $stopReference * (1 - $slip) * (1 - $fee);
        $targetNetPerUnit = $targetReference * (1 - $slip) * (1 - $fee);
        $lossPerUnit = $entryTotalPerUnit - $stopNetPerUnit;
        $gainPerUnit = $targetNetPerUnit - $entryTotalPerUnit;
        if (!is_finite($targetReference) || !is_finite($entryTotalPerUnit) || $entryTotalPerUnit <= 0
            || !is_finite($stopNetPerUnit) || !is_finite($targetNetPerUnit)
            || !is_finite($lossPerUnit) || $lossPerUnit <= 0 || !is_finite($gainPerUnit)) {
            throw new \InvalidArgumentException('Biaya atau estimasi risiko numerik tidak valid.');
        }

        $riskBudget = $equity * $riskPct / 100.0;
        $exposureBudget = $equity * $exposurePct / 100.0;
        // Full units; no assumptions about exchange-specific lot rules.
        $riskUnits = floor($riskBudget / $lossPerUnit);
        $exposureUnits = floor($exposureBudget / $entryTotalPerUnit);
        if (!is_finite($riskUnits) || !is_finite($exposureUnits)
            || $riskUnits > PHP_INT_MAX || $exposureUnits > PHP_INT_MAX) {
            throw new \InvalidArgumentException('Skala harga/unit terlalu ekstrem untuk kalkulasi unit penuh.');
        }
        $units = (int) max(0.0, min($riskUnits, $exposureUnits));
        $cashUsed = $units * $entryTotalPerUnit;
        $modelledLoss = $units * $lossPerUnit;
        $modelledGain = $units * $gainPerUnit;

        return [
            'ticker' => $document['ticker'],
            'kind' => $document['kind'],
            'source_name' => $document['source_name'],
            'imported_at' => $document['imported_at'],
            'last_date' => $latest['date'],
            'observations' => $total,
            'reference_close' => round($reference, 4),
            'atr14' => round($atr, 4),
            'atr14_pct' => round(100 * $atr / $reference, 2),
            'stop_reference' => round($stopReference, 4),
            'target_reference' => round($targetReference, 4),
            'equity' => $equity,
            'risk_pct' => $riskPct,
            'exposure_pct' => $exposurePct,
            'atr_multiple' => $atrMultiple,
            'reward_multiple' => $rewardMultiple,
            'fee_bps' => $feeBps,
            'slippage_bps' => $slippageBps,
            'risk_budget' => round($riskBudget, 2),
            'exposure_budget' => round($exposureBudget, 2),
            'risk_limited_units' => (int) $riskUnits,
            'exposure_limited_units' => (int) $exposureUnits,
            'units' => $units,
            'notional_with_entry_costs' => round($cashUsed, 2),
            'modelled_loss_at_stop' => round($modelledLoss, 2),
            'modelled_gain_at_target' => round($modelledGain, 2),
            'net_reward_to_risk' => $modelledLoss > 0 ? round($modelledGain / $modelledLoss, 2) : null,
            'budget_utilization_pct' => $riskBudget > 0 ? round(100 * $modelledLoss / $riskBudget, 1) : 0,
            'effective_exposure_pct' => round(100 * $cashUsed / $equity, 2),
            'binding_constraint' => $riskUnits <= $exposureUnits ? 'RISK_BUDGET' : 'EXPOSURE_CAP',
            'evidence' => 'USER_IMPORTED_UNVERIFIED',
            'decision' => 'HOLD',
            'trading_authorized' => false,
        ];
    }
}
