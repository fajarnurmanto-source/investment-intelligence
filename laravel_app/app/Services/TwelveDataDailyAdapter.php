<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/** Explicitly licensed EOD equities; no implicit demo or fallback credentials. */
final class TwelveDataDailyAdapter
{
    public const URL = 'https://api.twelvedata.com/time_series';

    public function fetch(array $cfg, array $metadata, ?string $checkpoint = null): array
    {
        try {
            $key = app(LocalByokCredentialVault::class)->key((int) $cfg['owner_id'], 'twelve_data');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('II_PROVIDER_CREDENTIAL_UNAVAILABLE');
        }
        $symbol = strtoupper(trim((string) ($cfg['provider_symbol'] ?? '')));
        $mic = strtoupper(trim((string) ($cfg['provider_mic'] ?? '')));
        if ($key === '' || strtolower($key) === 'demo' || !preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $key)
            || !preg_match('/^[A-Z0-9.\-]{1,16}$/D', $symbol) || !preg_match('/^[A-Z0-9]{4}$/D', $mic)
            || $metadata['price_adjustment'] !== 'unadjusted') {
            throw new \InvalidArgumentException('II_PROVIDER_CONFIGURATION_INVALID');
        }
        $end = (new \DateTimeImmutable('yesterday', new \DateTimeZone($metadata['session_timezone'])))->format('Y-m-d');
        $limit = filter_var($cfg['history_sessions'] ?? 250, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 60, 'max_range' => 2000]]);
        if (!$limit) {
            throw new \InvalidArgumentException('II_PROVIDER_HISTORY_LIMIT_INVALID');
        }
        $start = null;
        if ($checkpoint !== null && ($cfg['incremental_enabled'] ?? true) === true) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $checkpoint);
            if (!$date || $date->format('Y-m-d') !== $checkpoint || $checkpoint > $end) {
                throw new \InvalidArgumentException('II_PROVIDER_CHECKPOINT_INVALID');
            }
            // Include the checkpoint and preceding calendar days for overlap checks.
            $start = $date->modify('-14 days')->format('Y-m-d');
        }
        $query = ['symbol' => $symbol, 'mic_code' => $mic, 'interval' => '1day',
            'outputsize' => $limit, 'end_date' => $end, 'adjust' => 'none', 'order' => 'asc'];
        if ($start !== null) {
            $query['start_date'] = $start;
        }
        app(MarketAcquisitionLedger::class)->reserve($cfg);
        try {
            $response = Http::acceptJson()->withHeaders(['Authorization' => 'apikey '.$key])
                ->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])
                ->get(self::URL, $query);
        } catch (\Throwable) {
            throw new \RuntimeException('II_PROVIDER_NETWORK_ERROR');
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new \InvalidArgumentException('II_PROVIDER_ENTITLEMENT_DENIED');
        }
        if ($response->status() === 429) {
            throw new \InvalidArgumentException('II_PROVIDER_RATE_LIMITED');
        }
        if (in_array($response->status(), [500, 502, 503, 504], true)) {
            throw new \RuntimeException('II_PROVIDER_TEMPORARY_ERROR');
        }
        if (!$response->successful() || strlen($response->body()) > 1048576) {
            throw new \InvalidArgumentException('II_PROVIDER_RESPONSE_REJECTED');
        }
        $body = $response->json();
        if (!is_array($body) || ($body['status'] ?? null) !== 'ok') {
            throw new \InvalidArgumentException(match ((int) ($body['code'] ?? 0)) {
                401, 403 => 'II_PROVIDER_ENTITLEMENT_DENIED',
                429 => 'II_PROVIDER_RATE_LIMITED',
                default => 'II_PROVIDER_RESPONSE_REJECTED',
            });
        }
        $meta = $body['meta'] ?? [];
        if (($meta['symbol'] ?? null) !== $symbol || ($meta['mic_code'] ?? null) !== $mic
            || ($meta['currency'] ?? null) !== $metadata['currency']
            || ($meta['exchange_timezone'] ?? null) !== $metadata['session_timezone']
            || ($meta['interval'] ?? null) !== '1day'
            || !is_array($body['values'] ?? null) || count($body['values']) > $limit) {
            throw new \InvalidArgumentException('II_PROVIDER_IDENTITY_MISMATCH');
        }
        $lines = ['date,open,high,low,close,volume'];
        foreach ($body['values'] as $row) {
            if (!is_array($row) || !is_string($row['datetime'] ?? null)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $row['datetime']) || $row['datetime'] > $end || ($start !== null && $row['datetime'] < $start)) {
                throw new \InvalidArgumentException('II_PROVIDER_ROW_INVALID');
            }
            $values = [$row['datetime']];
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                $value = $row[$field] ?? null;
                if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value)) {
                    throw new \InvalidArgumentException('II_PROVIDER_ROW_INVALID');
                }
                $values[] = (string) $value;
            }
            $lines[] = implode(',', $values);
        }
        return ['csv' => implode("\n", $lines)."\n", 'response_sha256' => hash('sha256', $response->body()), 'request_start' => $start, 'request_end' => $end, 'request_limit' => $limit];
    }
}
