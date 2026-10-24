<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Routing\Controller;

class CompanyResearchController extends Controller
{
    private function base(): string { return rtrim(config('services.ii_engine.url', 'http://127.0.0.1:8811'), '/'); }
    private function query(string $path): array {
        try {
            $response = Http::timeout(4)->acceptJson()->get($this->base().$path);
            if (!$response->successful()) return ['error'=>'Python service returned HTTP '.$response->status()];
            return $response->json() ?: ['error'=>'Empty engine response'];
        } catch (\Throwable $ex) { return ['error'=>'Python service unavailable; start VS2 on 127.0.0.1:8811']; }
    }
    public function index(Request $request) {
        $universe = $this->query('/api/v2/companies');
        $ticker = $request->query('ticker', 'DEMO2');
        if (!in_array($ticker, ['DEMO2','DEMO3'], true)) abort(404);
        $research = $this->query('/api/v2/companies/'.$ticker.'/research');
        return view('ii.company', compact('ticker','universe','research'));
    }
    public function scenario(Request $request) {
        $data = $request->validate([
            'ticker'=>['required','in:DEMO2,DEMO3'],
            'observed_value'=>['required','numeric','between:-100,100'],
            'minimum_value'=>['required','numeric','between:-100,100'],
            'falsification_below'=>['required','numeric','between:-100,100'],
        ]);
        if ((float)$data['falsification_below'] > (float)$data['minimum_value']) return back()->withErrors(['falsification_below'=>'Falsification threshold cannot exceed support threshold'])->withInput();
        try {
            $response = Http::timeout(5)->acceptJson()->post($this->base().'/api/companies/'.$data['ticker'].'/scenario', [
                'metric'=>'Net_Margin_Pct',
                'observed_value'=>(float)$data['observed_value'],
                'minimum_value'=>(float)$data['minimum_value'],
                'falsification_below'=>(float)$data['falsification_below'],
            ]);
            if (!$response->successful()) return back()->withErrors(['engine'=>'Scenario rejected by engine (HTTP '.$response->status().')']);
            $result=$response->json();
            // Prevent an untrusted service from displaying an effective investment approval.
            $result['effective_research_state']='HOLD';
            $result['evidence_admission']='NOT_ADMITTED';
            $result['valuation_authorized']=false;
            $result['trading_authorized']=false;
            return redirect('/ii/company?ticker='.$data['ticker'])->with('scenario_result', $result);
        } catch (\Throwable $ex) { return back()->withErrors(['engine'=>'Python service unavailable'])->withInput(); }
    }
}
