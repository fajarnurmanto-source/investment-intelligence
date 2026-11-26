<?php

namespace App\Http\Controllers;

use App\Services\MarketCoverageService;
use App\Services\MarketFeedSettings;
use App\Services\LocalByokCredentialVault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MarketDataWorkspaceController extends Controller
{
    private function localOnly(Request $request): void
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true)
            && in_array(strtolower($request->getHost()), ['localhost', '127.0.0.1', '::1'], true), 403);
    }

    private function manager(Request $request, MarketFeedSettings $settings): int
    {
        $this->localOnly($request);
        $owner = (int) $request->user()->id;
        abort_unless($settings->canManage($owner), 403);
        return $owner;
    }

    public function index(Request $request, MarketCoverageService $coverage, MarketFeedSettings $settings, LocalByokCredentialVault $vault)
    {
        $this->localOnly($request);
        $owner = (int) $request->user()->id;
        $cfg = $settings->current();
        $manage = $settings->canManage($owner);
        $control = null;
        if ($manage) {
            $control = [
                'configured' => DB::table('ii_market_feed_settings')->where('id', 1)->where('user_id', $owner)->exists()
                    && trim((string) ($cfg['rights_reference'] ?? '')) !== ''
                    && ($cfg['storage_permitted'] ?? false) === true && ($cfg['automation_permitted'] ?? false) === true
                    && !empty($cfg['mics']),
                'connected' => $vault->connected($owner, 'twelve_data'),
                'mics' => implode(', ', $cfg['mics'] ?? []),
                'daily_limit' => $cfg['daily_request_limit'] ?? 10,
                'daily_used' => (int) DB::table('ii_market_request_days')->where('provider', 'twelve_data')->where('utc_day', now('UTC')->toDateString())->value('attempts'),
                'provider_credits' => app(\App\Services\MarketProviderCreditGuard::class)->remaining($owner),
                'discovery_enabled' => ($cfg['discovery_enabled'] ?? false) === true,
                'enabled' => ($cfg['enabled'] ?? false) === true,
                'catalog_count' => DB::table('ii_market_security_master')->where('user_id', $owner)
                    ->where('status', 'CATALOG_ONLY_ENTITLEMENT_UNVERIFIED')->count(),
            ];
        }
        return response()->view('ii.market-data', ['overview' => $coverage->overview($owner, $cfg), 'control' => $control])
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function policy(Request $request, MarketFeedSettings $settings)
    {
        $owner = $this->manager($request, $settings);
        $data = $request->validate([
            'mics' => ['required', 'string', 'max:80'],
            'rights_reference' => ['required', 'string', 'max:400'],
            'daily_request_limit' => ['required', 'integer', 'min:3', 'max:10000'],
            'storage_permitted' => ['accepted'], 'automation_permitted' => ['accepted'],
        ]);
        try {
            $settings->savePolicy($owner, [
                'mics' => preg_split('/[\s,]+/', strtoupper(trim($data['mics'])), -1, PREG_SPLIT_NO_EMPTY),
                'rights_reference' => $data['rights_reference'], 'daily_request_limit' => $data['daily_request_limit'],
                'storage_permitted' => true, 'automation_permitted' => true,
            ]);
        } catch (\Throwable) {
            return back()->withErrors(['policy' => 'Kebijakan ditolak atau proses lain sedang berjalan.']);
        }
        return redirect()->route('ii.market-data.index')->with('market_status', 'POLICY_SAVED_DISABLED');
    }

    public function credential(Request $request, MarketFeedSettings $settings)
    {
        $owner = $this->manager($request, $settings);
        $validation = Validator::make($request->only('api_key'), [
            'api_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,128}$/D', 'not_in:demo,DEMO'],
        ]);
        if ($validation->fails()) {
            return redirect()->route('ii.market-data.index')->withErrors(['credential' => 'Kredensial tidak valid.']);
        }
        try {
            // Disable both operations before replacing credentials; never flash the secret.
            $settings->replaceCredential($owner, $validation->validated()['api_key']);
        } catch (\Throwable) {
            return redirect()->route('ii.market-data.index')->withErrors(['credential' => 'Simpan kebijakan dahulu; penyimpanan kredensial gagal atau sedang terkunci.']);
        }
        return redirect()->route('ii.market-data.index')->with('market_status', 'KEY_STORED_DISABLED');
    }

    public function action(Request $request, MarketFeedSettings $settings)
    {
        $owner = $this->manager($request, $settings);
        $data = $request->validate(['action' => ['required', 'in:discovery,activate,stop'], 'confirm' => ['accepted']]);
        try {
            match ($data['action']) {
                'discovery' => $settings->authorizeDiscovery($owner),
                'activate' => $settings->setEnabled($owner, true),
                'stop' => $settings->setEnabled($owner, false),
            };
        } catch (\Throwable) {
            return back()->withErrors(['operation' => 'Operasi ditolak: periksa konfigurasi, kandidat, kredensial atau proses yang sedang berjalan.']);
        }
        return redirect()->route('ii.market-data.index')->with('market_status', strtoupper($data['action']).'_ACCEPTED');
    }
}
