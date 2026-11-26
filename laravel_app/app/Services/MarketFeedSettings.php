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

    public function save(int $owner, array $input): void
    {
        Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($owner, $input): void {
            if (!$this->canManage($owner)) {
                throw new \InvalidArgumentException('II_FEED_OWNER_MISMATCH');
            }
            $items = json_decode($input['universe_json'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($items) || !array_is_list($items)) {
                throw new \InvalidArgumentException('II_UNIVERSE_INVALID');
            }
            $cfg = [
                'provider' => 'twelve_data', 'enabled' => false,
                'source_name' => 'Twelve Data', 'source_url' => TwelveDataDailyAdapter::URL,
                'rights_reference' => trim($input['rights_reference']),
                'storage_permitted' => true, 'automation_permitted' => true,
                'history_sessions' => (int) $input['history_sessions'],
                'daily_request_limit' => (int) $input['daily_request_limit'],
                'request_budget' => min(50, (int) $input['daily_request_limit']),
                'incremental_enabled' => true,
                'universe_json' => json_encode($items, JSON_THROW_ON_ERROR),
            ];
            app(MarketUniverseRegistry::class)->entries($cfg + ['owner_id' => $owner]);
            DB::table('ii_market_feed_settings')->updateOrInsert(['id' => 1], [
                'user_id' => $owner, 'configuration' => json_encode($cfg, JSON_THROW_ON_ERROR),
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
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
            DB::table('ii_market_feed_settings')->where('id', 1)->where('user_id', $owner)
                ->update(['configuration' => json_encode($cfg, JSON_THROW_ON_ERROR), 'updated_at' => now('UTC')]);
        });
    }
}
