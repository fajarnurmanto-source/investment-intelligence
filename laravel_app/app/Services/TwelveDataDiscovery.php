<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class TwelveDataDiscovery
{
    /** Catalog presence is not a price entitlement or license decision. */
    public function discover(array $policy, string $mic): array
    {
        if (($policy['provider'] ?? null) !== 'twelve_data'
            || ($policy['storage_permitted'] ?? false) !== true
            || ($policy['automation_permitted'] ?? false) !== true
            || trim((string) ($policy['rights_reference'] ?? '')) === ''
            || !preg_match('/^[A-Z0-9]{4}$/D', $mic)) {
            throw new \InvalidArgumentException('II_DISCOVERY_POLICY_REQUIRED');
        }
        try {
            $key = app(LocalByokCredentialVault::class)->key((int) $policy['owner_id'], 'twelve_data');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('II_PROVIDER_CREDENTIAL_UNAVAILABLE');
        }
        $exchanges = $this->request($policy, $key, 'exchanges', ['mic_code' => $mic]);
        $zones = [];
        foreach ($exchanges as $exchange) {
            if (is_array($exchange) && ($exchange['mic_code'] ?? null) === $mic
                && in_array($exchange['timezone'] ?? null, MarketSourceMetadataService::SUPPORTED_TIMEZONES, true)) {
                $zones[] = $exchange['timezone'];
            }
        }
        $zones = array_values(array_unique($zones));
        if (count($zones) !== 1) {
            throw new \InvalidArgumentException('II_DISCOVERY_EXCHANGE_AMBIGUOUS');
        }
        $stocks = $this->request($policy, $key, 'stocks', ['mic_code' => $mic]);
        $candidates = [];
        $conflicts = [];
        foreach ($stocks as $stock) {
            if (!is_array($stock) || ($stock['mic_code'] ?? null) !== $mic
                || ($stock['type'] ?? null) !== 'Common Stock'
                || !is_string($stock['symbol'] ?? null) || !is_string($stock['currency'] ?? null)) {
                continue;
            }
            $symbol = strtoupper(trim($stock['symbol']));
            $currency = strtoupper(trim($stock['currency']));
            if (!preg_match('/^[A-Z0-9.\-]{1,16}$/D', $symbol)
                || str_starts_with($symbol, 'DEMO') || !preg_match('/^[A-Z]{3}$/D', $currency)) {
                continue;
            }
            $entry = ['ticker' => $symbol, 'provider_symbol' => $symbol, 'provider_mic' => $mic,
                'currency' => $currency, 'price_adjustment' => 'unadjusted', 'session_timezone' => $zones[0]];
            if (isset($candidates[$symbol]) && $candidates[$symbol] !== $entry) {
                $conflicts[$symbol] = true;
            }
            $candidates[$symbol] = $entry;
        }
        $candidates = array_diff_key($candidates, $conflicts);
        ksort($candidates);
        return ['provider' => 'twelve_data', 'mic' => $mic, 'status' => 'CATALOG_ONLY_ENTITLEMENT_UNVERIFIED',
            'observed_at' => now('UTC')->toIso8601String(), 'conflict_count' => count($conflicts),
            'candidates' => array_values($candidates)];
    }

    private function request(array $policy, string $key, string $endpoint, array $query): array
    {
        app(ZeroCostAcquisitionPolicy::class)->assertAllowed(new \GuzzleHttp\Psr7\Request('GET', 'https://api.twelvedata.com/'.$endpoint));
        $credits = app(MarketProviderCreditGuard::class);
        $credits->assertAvailable((int) $policy['owner_id']);
        app(MarketAcquisitionLedger::class)->reserve($policy);
        $credits->debit((int) $policy['owner_id']);
        try {
            $response = Http::acceptJson()->withHeaders(['Authorization' => 'apikey '.$key])
                ->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])
                ->get('https://api.twelvedata.com/'.$endpoint, $query);
        } catch (\Throwable) {
            throw new \RuntimeException('II_DISCOVERY_NETWORK_ERROR');
        }
        $credits->observe((int) $policy['owner_id'], $response);
        if (in_array($response->status(), [401, 403], true)) {
            throw new \InvalidArgumentException('II_PROVIDER_ENTITLEMENT_DENIED');
        }
        if (in_array($response->status(), [500, 502, 503, 504], true)) {
            throw new \RuntimeException('II_PROVIDER_TEMPORARY_ERROR');
        }
        if ($response->status() === 429) {
            throw new \InvalidArgumentException('II_PROVIDER_RATE_LIMITED');
        }
        if (!$response->successful() || strlen($response->body()) > 8388608) {
            throw new \InvalidArgumentException('II_DISCOVERY_RESPONSE_REJECTED');
        }
        $body = $response->json();
        if (is_array($body) && ($body['status'] ?? null) !== 'ok' && (int) ($body['code'] ?? 0) === 429) {
            $credits->markExhausted((int) $policy['owner_id']);
            throw new \InvalidArgumentException('II_PROVIDER_RATE_LIMITED');
        }
        if (!is_array($body) || ($body['status'] ?? null) !== 'ok'
            || !is_array($body['data'] ?? null) || !array_is_list($body['data'])
            || count($body['data']) > 30000) {
            throw new \InvalidArgumentException('II_DISCOVERY_RESPONSE_REJECTED');
        }
        return $body['data'];
    }
}
