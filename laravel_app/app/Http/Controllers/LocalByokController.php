<?php

namespace App\Http\Controllers;

use App\Services\ByokRightsPolicy;
use App\Services\LocalByokCredentialVault;
use App\Services\MarketDataByokQuoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class LocalByokController extends Controller
{
    private function localOnly(Request $request): void
    {
        if (! in_array($request->ip(), ['127.0.0.1', '::1'], true)
            || ! in_array(strtolower($request->getHost()), ['localhost', '127.0.0.1', '::1'], true)) {
            abort(403, 'II_BYOK_LOCAL_DEVICE_REQUIRED');
        }
    }

    public function index(Request $request, LocalByokCredentialVault $vault, ByokRightsPolicy $rights)
    {
        $this->localOnly($request);
        return response()->view('ii.byok', [
            'connected' => $vault->connected((int) $request->user()->id, 'marketdata'),
            'capabilities' => $rights->capabilities('marketdata'),
            'quote' => null,
            'status' => null,
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function connect(Request $request, LocalByokCredentialVault $vault)
    {
        $this->localOnly($request);
        $data = $request->validate([
            'provider' => ['required', 'in:marketdata'],
            'api_key' => ['required', 'string', 'max:512'],
            'confirm_private_subscription' => ['accepted'],
        ]);
        try {
            $vault->save((int) $request->user()->id, $data['provider'], $data['api_key']);
        } catch (\InvalidArgumentException) {
            return back()->withErrors(['api_key' => 'API key tidak valid.']);
        }

        return redirect()->route('ii.byok.index')->with('byok_status', 'KEY_STORED_LOCALLY');
    }

    public function disconnect(Request $request, LocalByokCredentialVault $vault)
    {
        $this->localOnly($request);
        $vault->disconnect((int) $request->user()->id, 'marketdata');
        return redirect()->route('ii.byok.index')->with('byok_status', 'DISCONNECTED');
    }

    public function quote(Request $request, LocalByokCredentialVault $vault, MarketDataByokQuoteService $marketData, ByokRightsPolicy $rights)
    {
        $this->localOnly($request);
        $data = $request->validate(['symbol' => ['required', 'string', 'regex:/^[A-Za-z]{1,5}(\.[A-Za-z])?$/']]);
        if (! $rights->allows('marketdata', 'display_in_app')
            || $rights->allows('marketdata', 'export')) {
            abort(403, 'II_BYOK_RIGHTS_BLOCKED');
        }
        try {
            $result = $marketData->quote($data['symbol'], $vault->key((int) $request->user()->id, 'marketdata'));
        } catch (\Throwable $exception) {
            // No credentials, request payload or raw provider body in logs.
            Log::notice('II BYOK local quote unavailable', ['class' => get_class($exception)]);
            return back()->withErrors(['symbol' => 'Data tidak tersedia; periksa key, kuota, dan entitlement.']);
        }
        return response()->view('ii.byok', [
            'connected' => true,
            'capabilities' => $rights->capabilities('marketdata'),
            'quote' => $result,
            'status' => 'IN_APP_DISPLAY_ONLY',
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'self'; form-action 'self'; object-src 'none'; base-uri 'none'");
    }
}
