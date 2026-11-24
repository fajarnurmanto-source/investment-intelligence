<?php

namespace App\Http\Controllers;

use App\Services\MacroAnalyticsService;
use App\Services\MacroReportRightsGate;
use App\Services\MacroReportLineageService;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\Response;

class MacroDashboardController extends Controller
{
    public function index(MacroAnalyticsService $analytics)
    {
        $dashboard = $analytics->dashboard();
        return response()->view('ii.macro', compact('dashboard'))->header('Cache-Control', 'private, no-store');
    }

    public function rights(MacroAnalyticsService $analytics, MacroReportRightsGate $gate): Response
    {
        return response()->json($gate->preflight($analytics->dashboard()), 200)
            ->header('Cache-Control', 'private, no-store');
    }

    public function report(MacroAnalyticsService $analytics, MacroReportRightsGate $gate, MacroReportLineageService $lineage): Response
    {
        $dashboard = $analytics->dashboard();
        $rights = $gate->preflight($dashboard);
        abort_unless($rights['status'] === 'REVIEWED_ALLOWED_LOCAL_NONCOMMERCIAL', 403, 'II_FRED_EXPORT_RIGHTS_REVIEW_REQUIRED');
        $writer = Writer::createFromString();
        $writer->insertOne(['series_id', 'title', 'observation_date', 'value', 'units', 'quality_status', 'retrieved_at', 'source_url']);
        foreach ($dashboard['series'] as $series) {
            $writer->insertOne([
                $series['id'], $series['title'], $series['latest_date'], $series['latest_value'],
                $series['units'], $series['quality_status'], (string) $series['retrieved_at'], $series['source_url'],
            ]);
        }

        $csv = $writer->toString();
        $audit = $lineage->attest((int) auth()->id(), $rights, $csv);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_MACRO_INTELLIGENCE_LATEST.csv"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-II-Lineage-Node' => $audit['node_id'],
            'X-II-Content-SHA256' => $audit['content_sha256'],
            'X-II-Rights-Scope' => 'LOCAL_PERSONAL_NONCOMMERCIAL_CSV',
        ]);
    }
}
