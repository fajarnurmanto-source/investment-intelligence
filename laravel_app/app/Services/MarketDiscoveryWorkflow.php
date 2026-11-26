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
            $settings = app(MarketFeedSettings::class);
            $current = $settings->current();
            if (!$settings->runtimeAuthorized($current)) {
                throw new \InvalidArgumentException('II_DISCOVERY_AUTHORIZATION_CHANGED');
            }
            if (($current['discovery_enabled'] ?? false) !== true || (int) ($current['owner_id'] ?? 0) !== $owner || $current !== $policy || ($current['mics'] ?? []) !== $mics) {
                throw new \InvalidArgumentException('II_DISCOVERY_AUTHORIZATION_CHANGED');
            }
            $counts = [];
            $reused = [];
            $deferred = [];
            $master = app(MarketSecurityMaster::class);
            $ledger = app(MarketAcquisitionLedger::class);
            $credits = app(MarketProviderCreditGuard::class);
            // Refresh stale/never-seen MICs first. Reserve at least one daily request for price acquisition.
            $ordered = $mics;
            $last = [];
            foreach ($mics as $mic) {
                $last[$mic] = $master->lastObservation($owner, $mic) ?? '';
            }
            usort($ordered, fn (string $a, string $b): int => [$last[$a], $a] <=> [$last[$b], $b]);
            foreach ($ordered as $mic) {
                if (!$settings->runtimeAuthorized($current)) {
                    throw new \InvalidArgumentException('II_DISCOVERY_AUTHORIZATION_CHANGED');
                }
                $fresh = $master->freshCount($owner, $mic);
                if ($fresh > 0) {
                    $counts[$mic] = $fresh;
                    $reused[] = $mic;
                    continue;
                }
                // A catalog refresh requires two requests; do not start a partial refresh.
                if ($ledger->remaining($policy) < 3 || ($credits->remaining($owner) ?? PHP_INT_MAX) < 2) {
                    $deferred[$mic] = 'BUDGET_DEFERRED';
                    continue;
                }
                try {
                    $catalog = app(TwelveDataDiscovery::class)->discover($policy, $mic);
                    $counts[$mic] = $master->ingest($owner, $catalog);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $code = $e->getMessage();
                    if (!in_array($code, ['II_PROVIDER_RATE_LIMITED', 'II_PROVIDER_DAILY_BUDGET_EXHAUSTED',
                        'II_DISCOVERY_NETWORK_ERROR', 'II_PROVIDER_TEMPORARY_ERROR', 'II_SECURITY_CATALOG_EMPTY'], true)) {
                        throw $e;
                    }
                    $deferred[$mic] = $code;
                    // Stop remote work on shared provider failures; retain last valid catalog.
                    if ($code !== 'II_SECURITY_CATALOG_EMPTY') {
                        break;
                    }
                }
            }
            if (!$settings->runtimeAuthorized($current)) {
                throw new \InvalidArgumentException('II_DISCOVERY_AUTHORIZATION_CHANGED');
            }
            foreach ($mics as $mic) {
                if (!array_key_exists($mic, $counts) && !array_key_exists($mic, $deferred)) {
                    $deferred[$mic] = 'NOT_ATTEMPTED_AFTER_PROVIDER_FAILURE';
                }
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
            return ['catalog_counts' => $counts, 'reused_mics' => $reused, 'deferred_mics' => $deferred, 'proposal' => $proposal];
        });
    }
}
