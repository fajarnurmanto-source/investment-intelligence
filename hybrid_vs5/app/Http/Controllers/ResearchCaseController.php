<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResearchCaseController extends Controller
{
    private function engine(): string
    {
        $url = (string) config('services.ii_engine.url', 'http://127.0.0.1:8811');
        $parts = parse_url($url);
        abort_unless(is_array($parts) && ($parts['scheme'] ?? null) === 'http' && ($parts['host'] ?? null) === '127.0.0.1' && ($parts['port'] ?? null) === 8811 && empty($parts['user']) && empty($parts['pass']) && empty($parts['path']) && empty($parts['query']), 503, 'VS5 requires local synthetic-only API at 127.0.0.1:8811');
        return 'http://127.0.0.1:8811';
    }

    private function snapshot(string $ticker): array
    {
        abort_unless(in_array($ticker, ['DEMO2', 'DEMO3'], true), 404);
        try {
            $res = Http::connectTimeout(2)->timeout(6)->acceptJson()->get($this->engine().'/api/v2/companies/'.$ticker.'/research');
            abort_unless($res->successful() && is_array($res->json()), 503, 'Synthetic engine unavailable');
            $data = $res->json();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            abort(503, 'Synthetic engine offline');
        }
        abort_unless(($data['ticker'] ?? null) === $ticker
          && ($data['admission'] ?? null) === 'NOT_ADMITTED'
          && ($data['authority'] ?? null) === 'NONE'
          && ($data['effective_decision'] ?? null) === 'HOLD'
          && ($data['valuation_authorized'] ?? null) === false
          && ($data['trading_authorized'] ?? null) === false
          && ($data['provenance']['source_type'] ?? null) === 'GENERATED_DEMO', 503, 'Boundary mismatch: fail closed');
        return $data;
    }

    public function index(): \Illuminate\Contracts\View\View
    {
        $cases = DB::table('ii_vs5_cases')->orderByDesc('created_at')->limit(100)->get();
        return view('ii.cases', ['cases' => $cases]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $input = $request->validate(['ticker'=>'required|in:DEMO2,DEMO3','title'=>'required|string|max:120']);
        $snapshot = $this->snapshot($input['ticker']);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $input, $snapshot) {
            DB::table('ii_vs5_cases')->insert([
                'id'=>$id, 'ticker'=>$input['ticker'], 'title'=>$input['title'],
                'company_name'=>(string) $snapshot['name'],
                'source_id'=>(string) $snapshot['provenance']['source_id'],
                'review_status'=>'OPEN', 'created_at'=>now(), 'updated_at'=>now(),
            ]);
            DB::table('ii_vs5_events')->insert([
                'id'=>(string) Str::uuid(), 'case_id'=>$id, 'kind'=>'CASE_CREATED',
                'review_status'=>'OPEN', 'finding'=>'Synthetic-only research case created', 'created_at'=>now(),
            ]);
        });
        return redirect('/ii/cases/'.$id);
    }

    private function findCase(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $case = DB::table('ii_vs5_cases')->where('id', $id)->first();
        abort_unless($case !== null, 404);
        return $case;
    }

    public function show(string $id): \Illuminate\Contracts\View\View
    {
        $case = $this->findCase($id);
        $data = $this->snapshot($case->ticker);
        $events = DB::table('ii_vs5_events')->where('case_id', $id)->orderBy('created_at')->orderBy('id')->get();
        return view('ii.case_detail', compact('case', 'data', 'events'));
    }

    public function note(Request $request, string $id): \Illuminate\Http\RedirectResponse
    {
        $case = $this->findCase($id);
        $input = $request->validate([
            'finding'=>'required|string|max:1000',
            'review_status'=>'required|in:OPEN,NEEDS_EVIDENCE,REVIEWED_SYNTHETIC',
        ]);
        $this->snapshot($case->ticker);
        DB::transaction(function () use ($id, $input) {
            DB::table('ii_vs5_events')->insert([
                'id'=>(string) Str::uuid(), 'case_id'=>$id, 'kind'=>'REVIEW_NOTE',
                'review_status'=>$input['review_status'], 'finding'=>$input['finding'], 'created_at'=>now(),
            ]);
            DB::table('ii_vs5_cases')->where('id', $id)->update(['review_status'=>$input['review_status'], 'updated_at'=>now()]);
        });
        return redirect('/ii/cases/'.$id);
    }

    public function report(string $id): Response
    {
        $case = $this->findCase($id);
        $data = $this->snapshot($case->ticker);
        $events = DB::table('ii_vs5_events')->where('case_id', $id)->orderBy('created_at')->orderBy('id')->get();
        $lines = [
            '# II Research Case: '.str_replace(["\r","\n"], ' ', $case->title), '',
            '> SYNTHETIC DEMO ONLY — NOT FINANCIAL ADVICE', '',
            'Case ID: '.$case->id, 'Ticker: '.$case->ticker,
            'Company: '.$case->company_name, 'Reviewer workflow status: '.$case->review_status,
            'Admission: NOT_ADMITTED', 'Authority: NONE', 'Effective decision: HOLD',
            'Valuation authorized: FALSE', 'Trading authorized: FALSE',
            'Current synthetic source: '.(string)$data['provenance']['source_id'],
            'Case original synthetic source: '.$case->source_id, '',
            '## Historical synthetic metrics', '', '| Period | Net margin % | Revenue index |','|---|---:|---:|',
        ];
        foreach (($data['history'] ?? []) as $row) {
            $lines[] = '| '.($row['period'] ?? '').' | '.($row['net_margin_pct'] ?? '').' | '.($row['revenue_index'] ?? '').' |';
        }
        $lines[]='';$lines[]='## Evidence blockers';
        foreach (($data['quality']['blocking_reasons'] ?? []) as $reason) $lines[] = '- '.str_replace(["\r","\n"], ' ', (string)$reason);
        $lines[]=''; $lines[]='## Local review events (not a canonical ledger)';
        foreach ($events as $event) {
            $finding = str_replace(["\r", "\n", '|'], [' ', ' ', '/'], (string) $event->finding);
            $lines[] = '- '.$event->created_at.' ['.$event->kind.'/'.$event->review_status.']: '.$finding;
        }
        $lines[]='';
        return response(implode("\n", $lines), 200, [
            'Content-Type'=>'text/markdown; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="II_VS5_'.$case->ticker.'_SYNTHETIC.md"',
            'Cache-Control'=>'no-store',
        ]);
    }
}
