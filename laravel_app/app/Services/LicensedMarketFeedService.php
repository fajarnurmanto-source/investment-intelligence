<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** Opt-in private rolling OHLCV snapshot supplied by a licensed external publisher. */
final class LicensedMarketFeedService
{
    public function __construct(private readonly SignalInsightService $signals) {}

    public function sync(?array $configuration = null): string
    {
        $cfg = $configuration ?? config('market_feed');
        if (($cfg['enabled'] ?? false) !== true) {
            return 'DISABLED';
        }
        if (($cfg['storage_permitted'] ?? false) !== true || ($cfg['automation_permitted'] ?? false) !== true
            || trim((string) ($cfg['rights_reference'] ?? '')) === '') {
            throw new \InvalidArgumentException('II_FEED_RIGHTS_REQUIRED');
        }
        $ticker = strtoupper(trim((string) ($cfg['ticker'] ?? '')));
        $owner = filter_var($cfg['owner_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$owner || !User::query()->whereKey($owner)->exists()
            || !preg_match('/^[A-Z0-9.\-]{1,16}$/D', $ticker) || str_starts_with($ticker, 'DEMO')) {
            throw new \InvalidArgumentException('II_FEED_CONFIGURATION_INVALID');
        }
        $provider = $cfg['provider'] ?? 'local';
        if (!in_array($provider, ['local', 'twelve_data'], true)) {
            throw new \InvalidArgumentException('II_PROVIDER_UNKNOWN');
        }
        $source = trim((string) ($cfg['source_name'] ?? ''));
        $url = trim((string) ($cfg['source_url'] ?? ''));
        if ($source === '' || strlen($source) > 120 || !filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || strlen($url) > 400
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null) {
            throw new \InvalidArgumentException('II_FEED_SOURCE_REQUIRED');
        }
        if ($provider === 'twelve_data' && ($source !== 'Twelve Data' || $url !== TwelveDataDailyAdapter::URL)) {
            throw new \InvalidArgumentException('II_PROVIDER_SOURCE_MISMATCH');
        }
        $metadata = MarketSourceMetadataService::forImport($cfg);
        if (!$metadata['currency'] || !$metadata['price_adjustment'] || !$metadata['session_timezone']) {
            throw new \InvalidArgumentException('II_FEED_METADATA_REQUIRED');
        }
        app(ByokIntakeFirewall::class)->assertIndependentSource(['source_name' => $source, 'source_url' => $url]);
        return Cache::lock('ii-market-feed-'.$owner.'-'.$ticker, 120)->block(5, function () use ($owner, $ticker, $source, $url, $cfg, $metadata, $provider): string {
            $responseHash = null;
            $mode = $provider === 'twelve_data' ? 'LICENSED_PROVIDER_API' : 'LICENSED_LOCAL_FEED';
            $identity = $provider === 'twelve_data' ? strtoupper($cfg['provider_symbol'] ?? '').'@'.strtoupper($cfg['provider_mic'] ?? '') : null;
            $previous = $this->signals->latestDocument($owner, $ticker);
            if ($previous !== null) {
                if (($previous['acquisition_mode'] ?? null) !== $mode
                    || ($previous['provider_identity'] ?? null) !== $identity
                    || $previous['source_name'] !== $source || ($previous['source_url'] ?? null) !== $url
                    || ($previous['rights_reference'] ?? null) !== $cfg['rights_reference']) {
                    throw new \InvalidArgumentException('II_FEED_EXISTING_SOURCE_CONFLICT');
                }
                foreach (['currency', 'price_adjustment', 'session_timezone'] as $field) {
                    if (($previous[$field] ?? null) !== $metadata[$field]) {
                        throw new \InvalidArgumentException('II_FEED_METADATA_CONFLICT');
                    }
                }
            }
            $checkpoint = null;
            $requestWindow = null;
            if ($provider === 'twelve_data') {
                $target = filter_var($cfg['history_sessions'] ?? 250, FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 60, 'max_range' => 2000]]);
                if (!$target) {
                    throw new \InvalidArgumentException('II_PROVIDER_HISTORY_LIMIT_INVALID');
                }
                if ($previous !== null && count($previous['rows']) >= $target
                    && ($cfg['incremental_enabled'] ?? true) === true) {
                    $checkpoint = $previous['rows'][array_key_last($previous['rows'])]['date'];
                }
                $result = app(TwelveDataDailyAdapter::class)->fetch($cfg, $metadata, $checkpoint);
                $raw = $result['csv'];
                $responseHash = $result['response_sha256'];
                $requestWindow = ['start' => $result['request_start'], 'end' => $result['request_end'], 'limit' => $result['request_limit']];
            } else {
            $disk = Storage::disk('local');
            $path = 'ii_market_feed/u'.$owner.'/'.$ticker.'.csv';
            if (!$disk->exists($path)) {
                return 'WAITING_FOR_FEED';
            }
            if ($disk->size($path) > 1048576) {
                throw new \InvalidArgumentException('II_FEED_SIZE_INVALID');
            }
            $raw = $disk->get($path);
            if (!is_string($raw) || strlen($raw) > 1048576) {
                throw new \InvalidArgumentException('II_FEED_SIZE_INVALID');
            }
            }
            $rows = $this->parse($raw, $metadata['session_timezone'], $provider === 'twelve_data' && $previous !== null ? 1 : 60);
            $fingerprint = hash('sha256', json_encode($provider === 'local' ? [$source, $url, $cfg['rights_reference'], $metadata, $rows] : [$source, $url, $cfg['rights_reference'], $metadata, $rows, $identity], JSON_THROW_ON_ERROR));
            if ($previous !== null) {
                if (($previous['feed_fingerprint'] ?? null) === $fingerprint) {
                    return 'UNCHANGED';
                }
                $oldRows = $previous['rows'];
                if (end($rows)['date'] < end($oldRows)['date']) {
                    throw new \InvalidArgumentException('II_FEED_STALE_SNAPSHOT');
                }
                $merged = array_column($oldRows, null, 'date');
                if ($provider === 'twelve_data' && array_intersect_key($merged, array_column($rows, null, 'date')) === []) {
                    throw new \InvalidArgumentException('II_FEED_HISTORY_OVERLAP_REQUIRED');
                }
                foreach ($rows as $row) {
                    if (isset($merged[$row['date']]) && $merged[$row['date']] != $row) {
                        throw new \InvalidArgumentException('II_FEED_HISTORICAL_CONFLICT');
                    }
                    $merged[$row['date']] = $row;
                }
                ksort($merged);
                $rows = array_slice(array_values($merged), -2000);
                if ($provider === 'twelve_data' && $rows == $oldRows) {
                    return 'UNCHANGED';
                }
            }
            $this->signals->save($owner, $ticker, array_merge([
                'schema' => 'ii.signal.vs7.r1',
                'ticker' => $ticker,
                'kind' => 'user_supplied',
                'source_name' => $source,
                'source_url' => $url,
                'imported_at' => now('Asia/Jakarta')->format('Y-m-d H:i:s.u T'),
                'acquisition_mode' => $mode,
                'provider_identity' => $identity,
                'provider_response_sha256' => $responseHash,
                'acquisition_window' => $requestWindow,
                'coverage_first_date' => $rows[0]['date'],
                'coverage_last_date' => $rows[array_key_last($rows)]['date'],
                'coverage_sessions' => count($rows),
                'rights_reference' => $cfg['rights_reference'],
                'feed_fingerprint' => $fingerprint,
                'source_sha256' => hash('sha256', $raw),
                'data_admission' => 'USER_IMPORTED_UNVERIFIED',
                'decision' => 'HOLD',
                'trading_authorized' => false,
                'rows' => $rows,
            ], $metadata));
            return 'IMPORTED';
        });
    }

    private function parse(string $raw, string $timezone, int $minimum = 60): array
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('II_FEED_STREAM_FAILED');
        }
        try {
            fwrite($handle, $raw);
            rewind($handle);
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (is_array($header)) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            }
            if ($header !== ['date', 'open', 'high', 'low', 'close', 'volume']) {
                throw new \InvalidArgumentException('II_FEED_HEADER_INVALID');
            }
            $rows = [];
            $today = (new \DateTimeImmutable('today', new \DateTimeZone($timezone)))->format('Y-m-d');
            while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($line === [null]) {
                    continue;
                }
                if (count($line) !== 6) {
                    throw new \InvalidArgumentException('II_FEED_ROW_INVALID');
                }
                $date = trim($line[0]);
                $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$dt || $dt->format('Y-m-d') !== $date || $date >= $today || isset($rows[$date])) {
                    throw new \InvalidArgumentException('II_FEED_DATE_INVALID');
                }
                foreach (array_slice($line, 1) as $number) {
                    if (!is_numeric($number) || !is_finite((float) $number)) {
                        throw new \InvalidArgumentException('II_FEED_NUMBER_INVALID');
                    }
                }
                [$open, $high, $low, $close, $volume] = array_map('floatval', array_slice($line, 1));
                if (min($open, $high, $low, $close) <= 0 || $low > min($open, $close) || $high < max($open, $close)
                    || $volume < 0 || floor($volume) != $volume) {
                    throw new \InvalidArgumentException('II_FEED_OHLCV_INVALID');
                }
                $rows[$date] = compact('date', 'open', 'high', 'low', 'close', 'volume');
                if (count($rows) > 2000) {
                    throw new \InvalidArgumentException('II_FEED_ROW_LIMIT');
                }
            }
            if (count($rows) < $minimum) {
                throw new \InvalidArgumentException('II_FEED_INSUFFICIENT_HISTORY');
            }
            ksort($rows);
            return array_values($rows);
        } finally {
            fclose($handle);
        }
    }
}
