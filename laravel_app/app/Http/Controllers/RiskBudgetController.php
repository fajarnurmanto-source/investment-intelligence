<?php

namespace App\Http\Controllers;

use App\Services\PositionRiskBudgetService;
use App\Services\SignalInsightService;
use Illuminate\Http\Request;

class RiskBudgetController extends Controller
{
    public function index(Request $request, SignalInsightService $market, PositionRiskBudgetService $engine)
    {
        $inputs = $request->validate([
            'ticker' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'equity' => ['nullable', 'numeric', 'between:1000,10000000000'],
            'risk_pct' => ['nullable', 'numeric', 'between:0.1,3'],
            'exposure_pct' => ['nullable', 'numeric', 'between:5,100'],
            'atr_multiple' => ['nullable', 'numeric', 'between:1,5'],
            'reward_multiple' => ['nullable', 'numeric', 'between:1,5'],
            'fee_bps' => ['nullable', 'integer', 'between:0,100'],
            'slippage_bps' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $available = $market->list((int) auth()->id());
        $ticker = strtoupper((string) ($inputs['ticker'] ?? ($available[0]['ticker'] ?? '')));
        $equity = (float) ($inputs['equity'] ?? 1000000);
        $riskPct = (float) ($inputs['risk_pct'] ?? 1.0);
        $exposurePct = (float) ($inputs['exposure_pct'] ?? 20.0);
        $atrMultiple = (float) ($inputs['atr_multiple'] ?? 2.0);
        $rewardMultiple = (float) ($inputs['reward_multiple'] ?? 2.0);
        $fee = (int) ($inputs['fee_bps'] ?? 15);
        $slippage = (int) ($inputs['slippage_bps'] ?? 10);
        $result = null;
        $signalContext = null;
        $warning = null;

        if ($ticker !== '') {
            $document = $market->latestDocument((int) auth()->id(), $ticker);
            if ($document === null) {
                $warning = 'Instrumen tidak ditemukan dalam data akun ini.';
            } else {
                try {
                    $result = $engine->estimate($document, $equity, $riskPct, $exposurePct, $atrMultiple, $rewardMultiple, $fee, $slippage);
                    $signalContext = $market->insight($document);
                } catch (\InvalidArgumentException $exception) {
                    $warning = $exception->getMessage();
                }
            }
        }

        return response()->view('ii.risk_budget', compact('available', 'ticker', 'equity', 'riskPct', 'exposurePct', 'atrMultiple', 'rewardMultiple', 'fee', 'slippage', 'result', 'signalContext', 'warning'))
            ->header('Cache-Control', 'private, no-store');
    }
}
