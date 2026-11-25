<?php

namespace App\Console\Commands;

use App\Services\LicensedMarketFeedService;
use Illuminate\Console\Command;

final class SyncLicensedMarketFeedCommand extends Command
{
    protected $signature = 'ii:sync-market-feed';

    protected $description = 'Synchronize an explicitly permitted private OHLCV rolling snapshot';

    public function handle(LicensedMarketFeedService $feed): int
    {
        try {
            $this->info('II_MARKET_FEED='.$feed->sync());
            return self::SUCCESS;
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('II_MARKET_FEED_SYNC_FAILED');
            return self::FAILURE;
        }
    }
}
