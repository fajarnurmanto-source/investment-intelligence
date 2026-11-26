<?php

namespace App\Console\Commands;

use App\Services\LicensedMarketFeedService;
use App\Services\MarketUniverseRegistry;
use App\Services\MarketAcquisitionLedger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Console\Command;

final class SyncLicensedMarketFeedCommand extends Command
{
    protected $signature = 'ii:sync-market-feed {--ticker= : Select one registered instrument}';

    protected $description = 'Synchronize an explicitly permitted private OHLCV rolling snapshot';

    public function handle(LicensedMarketFeedService $feed, MarketUniverseRegistry $registry, MarketAcquisitionLedger $ledger): int
    {
        try {
            $base = config('market_feed');
            if (($base['enabled'] ?? false) !== true) {
                $this->info('II_MARKET_FEED=DISABLED');
                return self::SUCCESS;
            }
            $entries = $registry->entries($base);
            $selected = strtoupper(trim((string) $this->option('ticker')));
            if ($selected !== '') {
                $entries = array_values(array_filter($entries, fn (array $entry): bool =>
                    strtoupper((string) ($entry['ticker'] ?? '')) === $selected));
                if ($entries === []) {
                    throw new \InvalidArgumentException('II_UNIVERSE_TICKER_NOT_FOUND');
                }
            }
            // Preserve the single-instrument command's existing output contract.
            $multi = !empty($base['universe_json']);
            if (!$multi) {
                $this->info('II_MARKET_FEED='.$ledger->run($entries[0], fn () => $feed->sync($entries[0])));
                return self::SUCCESS;
            }
            // A shared batch lock prevents overlapping universe runs, including
            // operator-selected runs. This is a per-run cap, not a daily quota.
            return Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($entries, $feed, $ledger): int {
                $failed = false;
                foreach ($entries as $entry) {
                    $ticker = $entry['ticker'];
                    try {
                        $this->info($ticker.'|II_MARKET_FEED='.$ledger->run($entry, fn () => $feed->sync($entry)));
                    } catch (\InvalidArgumentException $exception) {
                        $failed = true;
                        $code = $exception->getMessage();
                        $this->error($ticker.'|'.(preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_FEED_VALIDATION_FAILED'));
                        if (in_array($code, ['II_PROVIDER_RATE_LIMITED', 'II_PROVIDER_ENTITLEMENT_DENIED', 'II_PROVIDER_DAILY_BUDGET_EXHAUSTED', 'II_PROVIDER_CREDENTIAL_UNAVAILABLE'], true)) {
                            break;
                        }
                    } catch (\Throwable) {
                        $failed = true;
                        $this->error($ticker.'|II_MARKET_FEED_SYNC_FAILED');
                    }
                }
                return $failed ? self::FAILURE : self::SUCCESS;
            });
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('II_MARKET_FEED_SYNC_FAILED');
            return self::FAILURE;
        }
    }
}
