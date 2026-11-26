<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class MarketDiscoveryWorkflow
{
    public function run(int $owner, array $policy, array $mics): array
    {
        if ($owner < 1 || (int) ($policy['owner_id'] ?? 0) !== $owner
            || ($policy['provider'] ?? null) !== 'twelve_data'
            || ($policy['storage_permitted'] ?? false) !== true
            || ($policy['automation_permitted'] ?? false) !== true
            || trim((string) ($policy['rights_reference'] ?? '')) === ''
            || $mics === [] || count($mics) > 10) {
            throw new \InvalidArgumentException('II_DISCOVERY_POLICY_REQUIRED');
        }
        foreach ($mics as $mic) {
            if (!is_string($mic) || !preg_match('/^[A-Z0-9]{4}$/D', $mic)) {
                throw new \InvalidArgumentException('II_UNIVERSE_SCOPE_INVALID');
            }
        }
        $mics = array_values(array_unique($mics));
        return Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($owner, $policy, $mics): array {
            $current = app(MarketFeedSettings::class)->current();
            if (($current['discovery_enabled'] ?? false) !== true || (int) ($current['owner_id'] ?? 0) !== $owner || $current !== $policy || ($current['mics'] ?? []) !== $mics) {
                throw new \InvalidArgumentException('II_DISCOVERY_AUTHORIZATION_CHANGED');
            }
            $counts = [];
            foreach ($mics as $mic) {
                $catalog = app(TwelveDataDiscovery::class)->discover($policy, $mic);
                $counts[$mic] = app(MarketSecurityMaster::class)->ingest($owner, $catalog);
            }
            $proposal = app(AutomaticMarketUniverse::class)->propose($owner, $policy, $mics);
            // Never silently change an active acquisition universe.
            if (($current['enabled'] ?? false) !== true && $proposal['entries'] !== []) {
                $row = DB::table('ii_market_feed_settings')->where('id', 1)->where('user_id', $owner)->first();
                if (!$row) {
                    throw new \InvalidArgumentException('II_FEED_CONFIGURATION_REQUIRED');
                }
                $stored = json_decode($row->configuration, true, 16, JSON_THROW_ON_ERROR);
                $stored['universe_json'] = json_encode($proposal['entries'], JSON_THROW_ON_ERROR);
                app(MarketUniverseRegistry::class)->entries($stored + ['owner_id' => $owner]);
                DB::table('ii_market_feed_settings')->where('id', 1)->where('user_id', $owner)
                    ->update(['configuration' => json_encode($stored, JSON_THROW_ON_ERROR), 'updated_at' => now('UTC')]);
            }
            return ['catalog_counts' => $counts, 'proposal' => $proposal];
        });
    }
}
