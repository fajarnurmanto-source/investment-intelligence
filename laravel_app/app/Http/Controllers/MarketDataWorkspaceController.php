<?php

namespace App\Http\Controllers;

use App\Services\MarketCoverageService;
use Illuminate\Http\Request;

final class MarketDataWorkspaceController extends Controller
{
    public function index(Request $request, MarketCoverageService $coverage)
    {
        if (!in_array($request->ip(), ['127.0.0.1', '::1'], true)
            || !in_array(strtolower($request->getHost()), ['localhost', '127.0.0.1', '::1'], true)) {
            abort(403);
        }
        return response()->view('ii.market-data', ['overview' => $coverage->overview((int) $request->user()->id, app(\App\Services\MarketFeedSettings::class)->current())])
            ->header('Cache-Control', 'private, no-store');
    }
}
