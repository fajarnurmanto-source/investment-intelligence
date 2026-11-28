<?php

namespace App\Http\Controllers;

use App\Services\CompanyResearchService;
use App\Services\FundamentalSnapshotService;
use App\Services\ScenarioEvaluationService;
use Illuminate\Http\Request;

class CompanyResearchController extends Controller
{
    public function index(Request $request, CompanyResearchService $companies, FundamentalSnapshotService $fundamentals)
    {
        $records = $fundamentals->latestByTicker((int) $request->user()->id);
        $requested = $request->query('ticker', '');
        abort_unless(is_string($requested) && strlen($requested) <= 16, 400);
        $ticker = strtoupper(trim($requested));
        $universe = $companies->universe();
        $record = $records[$ticker] ?? null;
        $demo = $record === null && in_array($ticker, ['DEMO2', 'DEMO3'], true);
        $research = $demo ? $companies->research($ticker) : null;

        return response()->view('ii.company', compact('ticker', 'universe', 'research', 'records', 'record', 'demo'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function scenario(Request $request, ScenarioEvaluationService $evaluator)
    {
        $data = $request->validate([
            'ticker' => ['required', 'in:DEMO2,DEMO3'],
            'observed_value' => ['required', 'numeric', 'between:-100,100'],
            'minimum_value' => ['required', 'numeric', 'between:-100,100'],
            'falsification_below' => ['required', 'numeric', 'between:-100,100'],
        ]);

        try {
            $scenario = $evaluator->evaluate(
                (float) $data['observed_value'],
                (float) $data['minimum_value'],
                (float) $data['falsification_below'],
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['falsification_below' => 'Batas yang menggugurkan asumsi tidak boleh melebihi target margin.'])->withInput();
        }

        $result = [
            'ticker' => $data['ticker'],
            'input_source' => 'USER_ENTERED_HYPOTHETICAL',
            'scenario_result' => $scenario,
            'effective_research_state' => 'HOLD',
            'evidence_admission' => 'NOT_ADMITTED',
            'valuation_authorized' => false,
            'trading_authorized' => false,
        ];

        return redirect()->route('ii.company', ['ticker' => $data['ticker']])->with('scenario_result', $result);
    }
}
