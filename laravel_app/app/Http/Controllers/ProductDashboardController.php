<?php

namespace App\Http\Controllers;

use App\Services\FundamentalSnapshotService;
use App\Services\MacroAnalyticsService;
use App\Services\SignalInsightService;
use Illuminate\Support\Facades\DB;

class ProductDashboardController extends Controller
{
    public function index(SignalInsightService $signals, FundamentalSnapshotService $fundamentals, MacroAnalyticsService $macro)
    {
        $observations = $signals->list((int) auth()->id());
        $cases = DB::table('ii_vs5_cases')->where('ii_created_by', auth()->id())->count();
        $issuerCount = count($fundamentals->latestByTicker((int) auth()->id()));
        $macroDashboard = $macro->dashboard();

        return response()->view('ii.dashboard', [
            'signals' => array_slice($observations, 0, 4),
            'signalCount' => count($observations),
            'researchCases' => $cases,
            'issuerCount' => $issuerCount,
            'macroSeriesCount' => count($macroDashboard['series']),
        ])->header('Cache-Control', 'private, no-store');
    }
}
