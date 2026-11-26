<?php

namespace App\Console\Commands;

use App\Services\MarketDiscoveryWorkflow;
use App\Services\MarketFeedSettings;
use Illuminate\Console\Command;

final class DiscoverMarketUniverseCommand extends Command
{
    protected $signature = 'ii:discover-market-universe';
    protected $description = 'Refresh the authorized local market catalog and prepare a bounded universe';

    public function handle(MarketFeedSettings $settings, MarketDiscoveryWorkflow $workflow): int
    {
        try {
            $cfg = $settings->current();
            if (($cfg['discovery_enabled'] ?? false) !== true) {
                $this->info('II_DISCOVERY=DISABLED');
                return self::SUCCESS;
            }
            $result = $workflow->run((int) $cfg['owner_id'], $cfg, $cfg['mics'] ?? []);
            $this->info('II_DISCOVERY='.($result['deferred_mics'] === [] ? 'COMPLETE' : 'PARTIAL_DEFERRED')
                .'; CANDIDATES='.count($result['proposal']['entries'])
                .'; REUSED='.count($result['reused_mics']).'; DEFERRED='.count($result['deferred_mics']));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $code = $e->getMessage();
            $this->error(preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_DISCOVERY_FAILED');
            return self::FAILURE;
        }
    }
}
