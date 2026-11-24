<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * User-device-only read of a single MarketData.app delayed quote.
 * No archive, database import, queue, CSV or external share endpoint.
 */
final class MarketDataByokQuoteService
{
    public function quote(string $symbol, string $key): array
    {
        $symbol = strtoupper(trim($symbol));
        if (! preg_match('/^[A-Z]{1,5}(?:\.[A-Z])?$/D', $symbol)) {
            throw new \InvalidArgumentException('BYOK_SYMBOL_INVALID');
        }
        try {
            $response = Http::withToken($key)->acceptJson()
                ->withHeaders(['User-Agent' => 'InvestmentIntelligence-BYOK/0.1 (local-device)'])
                ->connectTimeout(5)->timeout(12)->withOptions(['allow_redirects' => false])
                ->get('https://api.marketdata.app/v1/stocks/quotes/'.$symbol.'/');
        } catch (\Throwable) {
            throw new \RuntimeException('BYOK_NETWORK_ERROR');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new \RuntimeException('BYOK_ENTITLEMENT_DENIED');
        }
        if ($response->status() === 429) {
            throw new \RuntimeException('BYOK_RATE_LIMITED');
        }
        if (! in_array($response->status(), [200, 203], true)) {
            throw new \RuntimeException('BYOK_PROVIDER_UNAVAILABLE');
        }
        $body = $response->body();
        if (strlen($body) > 131072 || $body === '') {
            throw new \RuntimeException('BYOK_RESPONSE_REJECTED');
        }
        $data = json_decode($body, true, 32);
        if (! is_array($data) || ($data['s'] ?? '') !== 'ok'
            || ! is_array($data['symbol'] ?? null)
            || strtoupper((string) ($data['symbol'][0] ?? '')) !== $symbol) {
            throw new \RuntimeException('BYOK_PROVIDER_SCHEMA_UNVERIFIED');
        }
        foreach (['bid','ask','last','updated'] as $field) {
            if (! isset($data[$field][0]) || ! is_numeric($data[$field][0])) {
                throw new \RuntimeException('BYOK_PROVIDER_SCHEMA_UNVERIFIED');
            }
        }
        return [
            'symbol' => $symbol,
            'bid' => (float) $data['bid'][0],
            'ask' => (float) $data['ask'][0],
            'last' => (float) $data['last'][0],
            'provider_timestamp' => (string) $data['updated'][0],
            'feed_class' => 'DELAYED_QUOTES_ENDPOINT',
            'real_time_confirmed' => false,
            'rights' => ByokRightsPolicy::RESTRICTED,
            'numeric_admission' => 'NOT_ADMITTED',
        ];
    }
}
