<?php

namespace App\Http\Controllers;

use App\Services\MacroAnalyticsService;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\Response;

class MacroDashboardController extends Controller
{
    public function index(MacroAnalyticsService $analytics)
    {
        $dashboard = $analytics->dashboard();
        return response()->view('ii.macro', compact('dashboard'))->header('Cache-Control', 'private, no-store');
    }

    public function report(MacroAnalyticsService $analytics): Response
    {
        $dashboard = $analytics->dashboard();
        $writer = Writer::createFromString();
        $writer->insertOne(['series_id', 'title', 'observation_date', 'value', 'units', 'quality_status', 'retrieved_at', 'source_url']);
        foreach ($dashboard['series'] as $series) {
            $writer->insertOne([
                $series['id'], $series['title'], $series['latest_date'], $series['latest_value'],
                $series['units'], $series['quality_status'], (string) $series['retrieved_at'], $series['source_url'],
            ]);
        }

        return response($writer->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="II_MACRO_INTELLIGENCE_LATEST.csv"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
