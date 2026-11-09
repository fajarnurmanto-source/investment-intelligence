<?php

namespace App\Http\Controllers;

use App\Services\RollingWalkForwardService;
use App\Services\SignalInsightService;
use Illuminate\Http\Request;

class RollingValidationController extends Controller
{
    public function loadDemo(SignalInsightService $market, RollingWalkForwardService $engine)
    {
        $rows = $engine->demoRows(new \DateTimeImmutable('today', new \DateTimeZone('Asia/Jakarta')));
        $market->save((int) auth()->id(), 'DEMO9', [
            'schema' => 'ii.signal.vs7.r1',
            'ticker' => 'DEMO9',
            'kind' => 'synthetic',
            'source_name' => 'II VS9 deterministic synthetic fixture — not market data',
            'source_url' => null,
            'imported_at' => now('Asia/Jakarta')->format('Y-m-d H:i:s.u T'),
            'data_admission' => 'USER_IMPORTED_UNVERIFIED',
            'decision' => 'HOLD',
            'trading_authorized' => false,
            'rows' => $rows,
        ]);
        return redirect()->route('ii.strategy.rolling', ['ticker' => 'DEMO9'])
            ->with('rolling_notice', 'DEMO9 sintetis telah dimuat ke akun lokal. Ini bukan harga pasar nyata.');
    }

    public function index(Request $request, SignalInsightService $market, RollingWalkForwardService $engine)
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
                $warning = 'Instrumen tidak ditemukan pada data akun ini.';
            } else {
                try {
                    $result = $engine->evaluate($document, $fee, $slippage);
                } catch (\InvalidArgumentException $exception) {
                    $warning = $exception->getMessage();
                }
            }
        }

        return response()->view('ii.rolling', compact('available', 'ticker', 'fee', 'slippage', 'result', 'warning'))
            ->header('Cache-Control', 'private, no-store');
    }
}
