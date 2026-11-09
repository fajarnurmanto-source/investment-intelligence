<?php

namespace App\Http\Controllers;

use App\Services\SignalInsightService;
use App\Services\StrategyValidationService;
use Illuminate\Http\Request;

class StrategyValidationController extends Controller
{
    public function index(Request $request, SignalInsightService $market, StrategyValidationService $validation)
    {
        $inputs = $request->validate([
            'ticker' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'fee_bps' => ['nullable', 'integer', 'between:0,100'],
            'slippage_bps' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $available = $market->list((int) auth()->id());
        $ticker = strtoupper((string) ($inputs['ticker'] ?? ($available[0]['ticker'] ?? '')));
        $fee = (int) ($inputs['fee_bps'] ?? 15);
        $slippage = (int) ($inputs['slippage_bps'] ?? 10);
        $result = null;
        $warning = null;

        if ($ticker !== '') {
            $document = $market->latestDocument((int) auth()->id(), $ticker);
            if ($document === null) {
                $warning = 'Instrumen tidak ditemukan pada data akun Anda.';
            } else {
                try {
                    $result = $validation->evaluate($document, $fee, $slippage);
                } catch (\InvalidArgumentException $exception) {
                    $warning = $exception->getMessage();
                }
            }
        }

        return response()->view('ii.validation', compact('available', 'ticker', 'fee', 'slippage', 'result', 'warning'))
            ->header('Cache-Control', 'private, no-store');
    }
}
