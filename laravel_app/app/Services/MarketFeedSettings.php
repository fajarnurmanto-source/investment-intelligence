<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class MarketFeedSettings
{
    public function current(): array
    {
        $row = DB::table('ii_market_feed_settings')->where('id', 1)->first();
        if ($row === null) {
            return config('market_feed');
        }
        $settings = json_decode($row->configuration, true, 16, JSON_THROW_ON_ERROR);
        return array_replace(config('market_feed'), $settings, ['owner_id' => (int) $row->user_id]);
    }

    public function canManage(int $owner): bool
    {
        $current = $this->current();
        $assigned = (int) ($current['owner_id'] ?? 0);
        return $assigned === $owner || ($assigned === 0 && !($current['enabled'] ?? false));
    }

    public function savePolicy(int $owner, array $input): void
    {
        Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($owner, $input): void {
            if (!$this->canManage($owner) || !\App\Models\User::query()->whereKey($owner)->exists()) {
                throw new \InvalidArgumentException('II_FEED_OWNER_MISMATCH');
            }
            $mics = $input['mics'] ?? [];
            $daily = filter_var($input['daily_request_limit'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 3, 'max_range' => 10000]]);
            $rights = trim((string) ($input['rights_reference'] ?? ''));
            if (!is_array($mics) || !array_is_list($mics) || !$mics || count($mics) > 10
                || !$daily || $rights === '' || strlen($rights) > 400
                || ($input['storage_permitted'] ?? false) !== true
                || ($input['automation_permitted'] ?? false) !== true) {
                throw new \InvalidArgumentException('II_DISCOVERY_POLICY_REQUIRED');
            }
            foreach ($mics as $mic) {
                if (!is_string($mic) || !preg_match('/^[A-Z0-9]{4}$/D', $mic)) {
                    throw new \InvalidArgumentException('II_UNIVERSE_SCOPE_INVALID');
                }
            }
            $cfg = [
                'provider' => 'twelve_data', 'enabled' => false, 'discovery_enabled' => false,
                'source_name' => 'Twelve Data', 'source_url' => TwelveDataDailyAdapter::URL,
                'rights_reference' => $rights, 'storage_permitted' => true, 'automation_permitted' => true,
                'history_sessions' => 250, 'daily_request_limit' => $daily,
                'request_budget' => min(50, $daily), 'incremental_enabled' => true,
                'mics' => array_values(array_unique($mics)), 'universe_json' => '[]',
            ];
            DB::table('ii_market_feed_settings')->updateOrInsert(['id' => 1], [
                'user_id' => $owner, 'configuration' => json_encode($cfg, JSON_THROW_ON_ERROR),
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        });
    }

    public function authorizeDiscovery(int $owner): void
    {
        Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($owner): void {
            $row = DB::table('ii_market_feed_settings')->where('id', 1)->where('user_id', $owner)->first();
            if (!$row) {
                throw new \InvalidArgumentException('II_FEED_CONFIGURATION_REQUIRED');
            }
            $cfg = json_decode($row->configuration, true, 16, JSON_THROW_ON_ERROR);
            $key = app(LocalByokCredentialVault::class)->key($owner, 'twelve_data');
            if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $key) || strtolower($key) === 'demo') {
                throw new \InvalidArgumentException('II_FEED_KEY_INVALID');
            }
            $cfg['discovery_enabled'] = true;
            DB::table('ii_market_feed_settings')->where('id', 1)->where('user_id', $owner)
                ->update(['configuration' => json_encode($cfg, JSON_THROW_ON_ERROR), 'updated_at' => now('UTC')]);
        });
    }

    public function replaceCredential(int $owner, string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $key) || strtolower($key) === 'demo') {
            throw new \InvalidArgumentException('II_FEED_KEY_INVALID');
        }
        Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($owner, $key): void {
            $row = DB::table('ii_market_feed_settings')->where('id', 1)->where('user_id', $owner)->first();
            if (!$row) {
                throw new \InvalidArgumentException('II_FEED_CONFIGURATION_REQUIRED');
            }
            $cfg = json_decode($row->configuration, true, 16, JSON_THROW_ON_ERROR);
            $cfg['enabled'] = false;
            $cfg['discovery_enabled'] = false;
            $cfg['universe_json'] = '[]';
            DB::table('ii_market_feed_settings')->where('id', 1)->where('user_id', $owner)
                ->update(['configuration' => json_encode($cfg, JSON_THROW_ON_ERROR), 'updated_at' => now('UTC')]);
            app(LocalByokCredentialVault::class)->save($owner, 'twelve_data', $key);
            Cache::forget('ii-twelve-data-credit-'.$owner);
        });
    }

    public function setEnabled(int $owner, bool $enabled): void
    {
        Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($owner, $enabled): void {
            $row = DB::table('ii_market_feed_settings')->where('id', 1)->where('user_id', $owner)->first();
            if ($row === null) {
                throw new \InvalidArgumentException('II_FEED_CONFIGURATION_REQUIRED');
            }
            $cfg = json_decode($row->configuration, true, 16, JSON_THROW_ON_ERROR);
            if ($enabled) {
                app(MarketUniverseRegistry::class)->entries($cfg + ['owner_id' => $owner]);
                $key = app(LocalByokCredentialVault::class)->key($owner, 'twelve_data');
                if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $key) || strtolower($key) === 'demo') {
                    throw new \InvalidArgumentException('II_FEED_KEY_INVALID');
                }
            }
            $cfg['enabled'] = $enabled;
            if (!$enabled) {
                $cfg['discovery_enabled'] = false;
            }
            DB::table('ii_market_feed_settings')->where('id', 1)->where('user_id', $owner)
                ->update(['configuration' => json_encode($cfg, JSON_THROW_ON_ERROR), 'updated_at' => now('UTC')]);
        });
    }
}
