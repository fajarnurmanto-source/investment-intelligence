<?php

namespace App\Http\Controllers;

use App\Services\CompanyResearchService;
use App\Services\ScenarioEvaluationService;
use Illuminate\Http\Request;

class CompanyResearchController extends Controller
{
    public function index(Request $request, CompanyResearchService $companies)
    {
        $ticker = strtoupper((string) $request->query('ticker', 'DEMO2'));
        $universe = $companies->universe();
        $research = $companies->research($ticker);

        return view('ii.company', compact('ticker', 'universe', 'research'));
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
            return back()->withErrors(['falsification_below' => $exception->getMessage()])->withInput();
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
