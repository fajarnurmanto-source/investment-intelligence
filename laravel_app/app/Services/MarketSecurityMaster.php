<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MarketSecurityMaster
{
    /** A successful complete MIC snapshot is reusable for 72 hours; never extends observed_at. */
    public function freshCount(int $owner, string $mic): int
    {
        return DB::table('ii_market_security_master')->where('user_id', $owner)
            ->where('provider', 'twelve_data')->where('mic', $mic)
            ->where('status', 'CATALOG_ONLY_ENTITLEMENT_UNVERIFIED')
            ->where('observed_at', '>=', now('UTC')->subHours(72))->count();
    }

    /** Only trusted internal discovery results; never an HTTP request body. */
    public function ingest(int $owner, array $catalog): int
    {
        if (!User::query()->whereKey($owner)->exists()
            || ($catalog['provider'] ?? null) !== 'twelve_data'
            || ($catalog['status'] ?? null) !== 'CATALOG_ONLY_ENTITLEMENT_UNVERIFIED'
            || !preg_match('/^[A-Z0-9]{4}$/D', $catalog['mic'] ?? '')
            || !is_array($catalog['candidates'] ?? null)
            || count($catalog['candidates']) > 30000) {
            throw new \InvalidArgumentException('II_SECURITY_CATALOG_INVALID');
        }
        $mic = $catalog['mic'];
        $rows = [];
        foreach ($catalog['candidates'] as $entry) {
            $symbol = $entry['provider_symbol'] ?? '';
            $currency = $entry['currency'] ?? '';
            $zone = $entry['session_timezone'] ?? '';
            if (($entry['provider_mic'] ?? null) !== $mic
                || !preg_match('/^[A-Z0-9.\-]{1,16}$/D', $symbol)
                || str_starts_with($symbol, 'DEMO')
                || !preg_match('/^[A-Z]{3}$/D', $currency)
                || !in_array($zone, MarketSourceMetadataService::SUPPORTED_TIMEZONES, true)
                || isset($rows[$symbol])) {
                throw new \InvalidArgumentException('II_SECURITY_ENTRY_INVALID');
            }
            $rows[$symbol] = ['user_id' => $owner, 'provider' => 'twelve_data', 'mic' => $mic,
                'symbol' => $symbol, 'currency' => $currency, 'session_timezone' => $zone,
                'status' => 'CATALOG_ONLY_ENTITLEMENT_UNVERIFIED', 'observed_at' => now('UTC')];
        }
        // A failed or empty refresh must not erase the last catalog.
        if ($rows === []) {
            throw new \InvalidArgumentException('II_SECURITY_CATALOG_EMPTY');
        }
        DB::transaction(function () use ($owner, $mic, $rows): void {
            DB::table('ii_market_security_master')->where('user_id', $owner)
                ->where('provider', 'twelve_data')->where('mic', $mic)
                ->update(['status' => 'NOT_SEEN_IN_LATEST_CATALOG']);
            foreach (array_chunk(array_values($rows), 100) as $chunk) {
                DB::table('ii_market_security_master')->upsert($chunk,
                    ['user_id', 'provider', 'mic', 'symbol'],
                    ['currency', 'session_timezone', 'status', 'observed_at']);
            }
        });
        return count($rows);
    }
}
