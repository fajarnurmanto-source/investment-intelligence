<?php

namespace App\Http\Controllers;

use App\Services\SignalInsightService;
use App\Services\SignalAnalyticsLineageService;
use App\Services\StrategyReplayService;
use Illuminate\Http\Request;

class StrategyLabController extends Controller
{
    public function index(Request $request, SignalInsightService $market, StrategyReplayService $engine)
    {
        $data = $request->validate([
            'ticker' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'preset' => ['nullable', 'in:10-30,20-50'],
            'fee_bps' => ['nullable', 'integer', 'between:0,100'],
            'slippage_bps' => ['nullable', 'integer', 'between:0,100'],
        ]);
        $available = $market->list((int) auth()->id());
        $ticker = strtoupper((string) ($data['ticker'] ?? ($available[0]['ticker'] ?? '')));
        $preset = (string) ($data['preset'] ?? '10-30');
        [$fast, $slow] = array_map('intval', explode('-', $preset));
        $fee = (int) ($data['fee_bps'] ?? 15);
        $slippage = (int) ($data['slippage_bps'] ?? 10);
        $result = null;
        $warning = null;

        if ($ticker !== '') {
            $document = $market->latestDocument((int) auth()->id(), $ticker);
            if ($document === null) {
                $warning = 'Ticker belum memiliki seri harga pada akun ini. Impor CSV melalui Signal Center.';
            } else {
                try {
                    $result = $engine->replay($document, $fast, $slow, $fee, $slippage);
                    $result = app(SignalAnalyticsLineageService::class)->strategy(
                        (int) auth()->id(), $document,
                        ['fast' => $fast, 'slow' => $slow, 'fee_bps' => $fee, 'slippage_bps' => $slippage], $result
                    );
                } catch (\InvalidArgumentException $exception) {
                    $warning = $exception->getMessage();
                }
            }
        }

        return response()->view('ii.strategy', compact('available', 'ticker', 'preset', 'fee', 'slippage', 'result', 'warning'))
            ->header('Cache-Control', 'private, no-store');
    }
}
