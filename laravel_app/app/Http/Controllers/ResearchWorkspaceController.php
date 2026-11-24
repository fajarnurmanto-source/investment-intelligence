<?php
namespace App\Http\Controllers;

use App\Services\CompanyResearchService;
use App\Services\SyntheticReportLineageService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResearchWorkspaceController extends Controller
{
    private function research(string $ticker, CompanyResearchService $companies): array
    {
        $data = $companies->research($ticker);
        if (($data['ticker'] ?? '') !== $ticker || ($data['admission'] ?? '') !== 'NOT_ADMITTED' || ($data['effective_decision'] ?? '') !== 'HOLD' || ($data['authority'] ?? '') !== 'NONE' || ($data['valuation_authorized'] ?? null) !== false || ($data['trading_authorized'] ?? null) !== false || ($data['provenance']['source_type'] ?? '') !== 'GENERATED_DEMO') {
            abort(503, 'II VS4 fail-closed: API research boundary mismatch');
        }
        return $data;
    }

    public function index(Request $request, CompanyResearchService $companies): \Illuminate\Contracts\View\View
    {
        $ticker = strtoupper((string) $request->query('ticker', 'DEMO2'));
        $universe = $companies->universe();
        $data = $this->research($ticker, $companies);
        $notes = $request->session()->get('ii_vs4_notes.' . $ticker, []);
        return view('ii.workspace', compact('ticker', 'universe', 'data', 'notes'));
    }

    public function note(Request $request): \Illuminate\Http\RedirectResponse
    {
        $input = $request->validate([
            'ticker' => 'required|in:DEMO2,DEMO3',
            'finding' => 'required|string|max:1000',
            'review_status' => 'required|in:OPEN,NEEDS_EVIDENCE,REVIEWED_SYNTHETIC',
        ]);
        $ticker = $input['ticker'];
        $notes = $request->session()->get('ii_vs4_notes.' . $ticker, []);
        if (!is_array($notes)) $notes = [];
        $notes[] = ['id' => (string) Str::uuid(), 'finding' => $input['finding'], 'review_status' => $input['review_status'], 'created_at' => now()->toIso8601String()];
        $request->session()->put('ii_vs4_notes.' . $ticker, array_slice($notes, -20));
        return redirect('/ii/workspace?ticker=' . $ticker)->with('ii_vs4_notice', 'Session-only reviewer note saved. Evidence remains NOT_ADMITTED.');
    }

    public function report(Request $request, CompanyResearchService $companies): Response
    {
        $ticker = strtoupper((string) $request->query('ticker', 'DEMO2'));
        $data = $this->research($ticker, $companies);
        $notes = $request->session()->get('ii_vs4_notes.' . $ticker, []);
        if (!is_array($notes)) $notes = [];
        $provenance = $data['provenance'];
        $lines = [
            '# II Research Workspace — ' . $ticker,
            '', '> SYNTHETIC DEMONSTRATION ONLY — NOT FINANCIAL ADVICE',
            '', 'Company: ' . $data['name'],
            'Admission: NOT_ADMITTED', 'Authority: NONE', 'Effective decision: HOLD', 'Valuation authorized: FALSE', 'Trading authorized: FALSE',
            '', '## Historical metrics (synthetic)', '', '| Period | Net margin % | Revenue index |', '|---|---:|---:|',
        ];
        foreach ($data['history'] as $row) {
            $lines[] = '| ' . ($row['period'] ?? '') . ' | ' . ($row['net_margin_pct'] ?? '') . ' | ' . ($row['revenue_index'] ?? '') . ' |';
        }
        $lines = array_merge($lines, ['', '## Provenance', '', 'Source ID: ' . $provenance['source_id'], 'Source type: GENERATED_DEMO', 'Rights: SYNTHETIC_ONLY', 'Lineage: ' . $provenance['lineage'], '', '## Evidence blockers', '']);
        foreach (($data['quality']['blocking_reasons'] ?? []) as $reason) $lines[] = '- ' . $reason;
        $lines = array_merge($lines, ['', '## Session-only reviewer notes', '']);
        if (!$notes) $lines[] = '- No reviewer notes in this browser session';
        foreach ($notes as $note) {
            $finding = str_replace(["\r", "\n", '|'], [' ', ' ', '/'], (string) ($note['finding'] ?? ''));
            $lines[] = '- ' . ($note['review_status'] ?? 'OPEN') . ': ' . $finding;
        }
        $lines[] = '';
        $content = implode("\n", $lines);
        $lineage = app(SyntheticReportLineageService::class)->attest(
            (int) auth()->id(), 'WORKSPACE_VS4', $data, $content
        );
        return response($content, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_VS4_' . $ticker . '_SYNTHETIC.md"',
            'Cache-Control' => 'private, no-store',
            'X-II-Lineage-Node' => $lineage['node_id'],
            'X-II-Lineage-Status' => $lineage['status'],
            'X-II-Content-SHA256' => $lineage['content_sha256'],
        ]);
    }
}
