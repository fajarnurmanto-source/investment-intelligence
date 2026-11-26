<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

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
            $counts = [];
            foreach ($mics as $mic) {
                $catalog = app(TwelveDataDiscovery::class)->discover($policy, $mic);
                $counts[$mic] = app(MarketSecurityMaster::class)->ingest($owner, $catalog);
            }
            return [
                'catalog_counts' => $counts,
                'proposal' => app(AutomaticMarketUniverse::class)->propose($owner, $policy, $mics),
            ];
        });
    }
}
