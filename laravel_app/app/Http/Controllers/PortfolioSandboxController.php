<?php

namespace App\Http\Controllers;

use App\Services\PortfolioScenarioService;
use App\Services\SignalInsightService;
use App\Services\SignalAnalyticsLineageService;
use Illuminate\Http\Request;

class PortfolioSandboxController extends Controller
{
    public function index(Request $request, SignalInsightService $market, PortfolioScenarioService $portfolio)
    {
        $inputs = $request->validate([
            'a' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'b' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'c' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'wa' => ['nullable', 'numeric', 'between:0,100'],
            'wb' => ['nullable', 'numeric', 'between:0,100'],
            'wc' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $available = $market->list((int) auth()->id());
        $a = strtoupper((string) ($inputs['a'] ?? ($available[0]['ticker'] ?? '')));
        $b = strtoupper((string) ($inputs['b'] ?? ($available[1]['ticker'] ?? '')));
        $c = strtoupper((string) ($inputs['c'] ?? ''));
        $wa = (float) ($inputs['wa'] ?? 50);
        $wb = (float) ($inputs['wb'] ?? 50);
        $wc = (float) ($inputs['wc'] ?? 0);
        $result = null;
        $warning = null;

        if (count($available) >= 2) {
            try {
                $selections = [];
                foreach ([[$a, $wa], [$b, $wb], [$c, $wc]] as [$ticker, $weight]) {
                    if ($ticker === '') {
                        if ($weight != 0.0) {
                            throw new \InvalidArgumentException('Bobot instrumen kosong harus 0%.');
                        }
                        continue;
                    }
                    if (isset($selections[$ticker])) {
                        throw new \InvalidArgumentException('Instrumen harus berbeda satu sama lain.');
                    }
                    $selections[$ticker] = $weight;
                }
                if (count($selections) < 2) {
                    throw new \InvalidArgumentException('Pilih minimal dua instrumen berbeda.');
                }
                $documents = [];
                foreach ($selections as $ticker => $weight) {
                    $document = $market->latestDocument((int) auth()->id(), $ticker);
                    if ($document === null) {
                        throw new \InvalidArgumentException('Instrumen '.$ticker.' tidak ditemukan pada akun ini.');
                    }
                    $documents[$ticker] = $document;
                }
                $result = $portfolio->analyze($documents, $selections);
                $result = app(SignalAnalyticsLineageService::class)->portfolio(
                    (int) auth()->id(), $documents, $selections, $result
                );
            } catch (\InvalidArgumentException $exception) {
                $warning = $exception->getMessage();
            }
        }

        return response()->view('ii.portfolio_sandbox', compact('available', 'a', 'b', 'c', 'wa', 'wb', 'wc', 'result', 'warning'))
            ->header('Cache-Control', 'private, no-store');
    }
}
