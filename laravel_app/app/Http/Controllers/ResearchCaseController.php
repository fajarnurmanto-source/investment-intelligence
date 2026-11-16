<?php

namespace App\Http\Controllers;

use App\Services\CompanyResearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResearchCaseController extends Controller
{
    private function requireResearchRole(): void
    {
        abort_unless(
            auth()->check() && in_array((string) auth()->user()->ii_research_role, ['analyst', 'reviewer', 'admin'], true),
            403,
            'Research role required'
        );
    }

    private function ownedCase(string $id): object
    {
        abort_unless(Str::isUuid($id), 404);

        return DB::table('ii_vs5_cases')
            ->where('id', $id)
            ->where('ii_created_by', auth()->id())
            ->first() ?? abort(404);
    }

    private function snapshot(string $ticker, CompanyResearchService $companies): array
    {
        $data = $companies->research($ticker);
        abort_unless(($data['ticker'] ?? null) === $ticker
            && ($data['admission'] ?? null) === 'NOT_ADMITTED'
            && ($data['authority'] ?? null) === 'NONE'
            && ($data['effective_decision'] ?? null) === 'HOLD'
            && ($data['valuation_authorized'] ?? null) === false
            && ($data['trading_authorized'] ?? null) === false
            && ($data['provenance']['source_type'] ?? null) === 'GENERATED_DEMO',
            503,
            'Boundary mismatch: fail closed'
        );

        return $data;
    }

    public function index(): View
    {
        $this->requireResearchRole();
        $cases = DB::table('ii_vs5_cases as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.ii_created_by')
            ->select('c.*', 'u.email as creator_email')
            ->where('c.ii_created_by', auth()->id())
            ->orderByDesc('c.created_at')
            ->limit(100)
            ->get();

        return view('ii.cases', ['cases' => $cases]);
    }

    public function store(Request $request, CompanyResearchService $companies): RedirectResponse
    {
        $this->requireResearchRole();
        $input = $request->validate([
            'ticker' => 'required|in:DEMO2,DEMO3',
            'title' => 'required|string|max:120',
        ]);
        $snapshot = $this->snapshot($input['ticker'], $companies);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $input, $snapshot) {
            DB::table('ii_vs5_cases')->insert([
                'id' => $id, 'ticker' => $input['ticker'], 'title' => $input['title'],
                'company_name' => (string) $snapshot['name'],
                'source_id' => (string) $snapshot['provenance']['source_id'],
                'review_status' => 'OPEN', 'ii_created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('ii_vs5_events')->insert([
                'id' => (string) Str::uuid(), 'case_id' => $id, 'kind' => 'CASE_CREATED',
                'review_status' => 'OPEN', 'ii_actor_id' => auth()->id(), 'finding' => 'Synthetic-only research case created', 'created_at' => now(),
            ]);
        });

        return redirect('/ii/cases/'.$id);
    }

    private function events(string $id): Collection
    {
        return DB::table('ii_vs5_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.ii_actor_id')
            ->select('e.*', 'u.email as actor_email')
            ->where('e.case_id', $id)
            ->orderBy('e.created_at')
            ->orderBy('e.id')
            ->get();
    }

    public function show(string $id, CompanyResearchService $companies): View
    {
        $this->requireResearchRole();
        $case = $this->ownedCase($id);
        $data = $this->snapshot($case->ticker, $companies);
        $events = $this->events($id);

        return view('ii.case_detail', compact('case', 'data', 'events'));
    }

    public function note(Request $request, string $id, CompanyResearchService $companies): RedirectResponse
    {
        $this->requireResearchRole();
        abort_unless(in_array((string) auth()->user()->ii_research_role, ['reviewer', 'admin'], true), 403, 'Reviewer role required');
        $case = $this->ownedCase($id);
        $input = $request->validate([
            'finding' => 'required|string|max:1000',
            'review_status' => 'required|in:OPEN,NEEDS_EVIDENCE,REVIEWED_SYNTHETIC',
        ]);
        $this->snapshot($case->ticker, $companies);
        DB::transaction(function () use ($id, $input) {
            DB::table('ii_vs5_events')->insert([
                'id' => (string) Str::uuid(), 'case_id' => $id, 'kind' => 'REVIEW_NOTE',
                'review_status' => $input['review_status'], 'ii_actor_id' => auth()->id(), 'finding' => $input['finding'], 'created_at' => now(),
            ]);
            DB::table('ii_vs5_cases')->where('id', $id)->update([
                'review_status' => $input['review_status'], 'updated_at' => now(),
            ]);
        });

        return redirect('/ii/cases/'.$id);
    }

    public function report(string $id, CompanyResearchService $companies): Response
    {
        $this->requireResearchRole();
        $case = $this->ownedCase($id);
        $data = $this->snapshot($case->ticker, $companies);
        $events = $this->events($id);
        $lines = [
            '# II Research Case: '.str_replace(["\r", "\n"], ' ', $case->title), '',
            '> SYNTHETIC DEMO ONLY — NOT FINANCIAL ADVICE', '',
            'Case ID: '.$case->id, 'Ticker: '.$case->ticker,
            'Company: '.$case->company_name, 'Reviewer workflow status: '.$case->review_status,
            'Admission: NOT_ADMITTED', 'Authority: NONE', 'Effective decision: HOLD',
            'Valuation authorized: FALSE', 'Trading authorized: FALSE',
            'Current synthetic source: '.(string) $data['provenance']['source_id'],
            'Case original synthetic source: '.$case->source_id, '',
            '## Historical synthetic metrics', '', '| Period | Net margin % | Revenue index |', '|---|---:|---:|',
        ];
        foreach (($data['history'] ?? []) as $row) {
            $lines[] = '| '.($row['period'] ?? '').' | '.($row['net_margin_pct'] ?? '').' | '.($row['revenue_index'] ?? '').' |';
        }
        $lines[] = '';
        $lines[] = '## Evidence blockers';
        foreach (($data['quality']['blocking_reasons'] ?? []) as $reason) {
            $lines[] = '- '.str_replace(["\r", "\n"], ' ', (string) $reason);
        }
        $lines[] = '';
        $lines[] = '## Local review events (not a canonical ledger)';
        foreach ($events as $event) {
            $finding = str_replace(["\r", "\n", '|'], [' ', ' ', '/'], (string) $event->finding);
            $actor = str_replace(["\r", "\n", '|'], [' ', ' ', '/'], (string) ($event->actor_email ?? 'legacy/unknown'));
            $lines[] = '- '.$event->created_at.' ['.$event->kind.'/'.$event->review_status.'] by '.$actor.': '.$finding;
        }
        $lines[] = '';

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_VS5_'.$case->ticker.'_SYNTHETIC.md"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
