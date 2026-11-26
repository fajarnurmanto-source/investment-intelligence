<?php

namespace App\Console\Commands;

use App\Jobs\SyncMarketUniverse;
use App\Services\LicensedMarketFeedService;
use App\Services\MarketUniverseRegistry;
use App\Services\MarketAcquisitionLedger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Console\Command;

final class SyncLicensedMarketFeedCommand extends Command
{
    protected $signature = 'ii:sync-market-feed {--ticker= : Select one registered instrument} {--queue : Dispatch registered instruments to the local database queue}';

    protected $description = 'Synchronize an explicitly permitted private OHLCV rolling snapshot';

    public function handle(LicensedMarketFeedService $feed, MarketUniverseRegistry $registry, MarketAcquisitionLedger $ledger): int
    {
        try {
            return Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($feed, $registry, $ledger): int {
            $settings = app(\App\Services\MarketFeedSettings::class);
            $base = $settings->current();
            if (($base['enabled'] ?? false) !== true) {
                $this->info('II_MARKET_FEED=DISABLED');
                return self::SUCCESS;
            }
            if (!$settings->runtimeAuthorized($base)) {
                $this->error('II_FEED_AUTHORIZATION_REVOKED');
                return self::FAILURE;
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
            if (($base['provider'] ?? 'local') === 'twelve_data' && $this->option('queue')) {
                $remaining = $ledger->remaining($base);
                $credits = app(\App\Services\MarketProviderCreditGuard::class)->remaining((int) $base['owner_id']);
                $cap = min($remaining, $credits ?? PHP_INT_MAX);
                if ($cap === 0) {
                    $this->info('II_MARKET_FEED=DEFERRED_BUDGET');
                    return self::SUCCESS;
                }
                // Least recently attempted first within the explicitly authorized universe.
                $attempts = \Illuminate\Support\Facades\DB::table('ii_market_acquisition_receipts')
                    ->where('user_id', (int) $base['owner_id'])->where('provider', 'twelve_data')
                    ->select('ticker')->selectRaw('MAX(started_at) as last_attempt')->groupBy('ticker')
                    ->pluck('last_attempt', 'ticker');
                usort($entries, fn (array $a, array $b): int =>
                    [($attempts[$a['ticker']] ?? ''), $a['ticker']] <=> [($attempts[$b['ticker']] ?? ''), $b['ticker']]);
                $entries = array_slice($entries, 0, $cap);
            }
            if ($this->option('queue')) {
                if (config('queue.default') !== 'database' || (int) config('queue.connections.database.retry_after') <= 120) {
                    throw new \InvalidArgumentException('II_MARKET_QUEUE_CONFIGURATION_INVALID');
                }
                foreach ($entries as $entry) {
                    $owner = (int) ($entry['owner_id'] ?? 0);
                    $ticker = strtoupper(trim((string) ($entry['ticker'] ?? '')));
                    if ($owner < 1 || !preg_match('/^[A-Z0-9.\-]{1,16}$/D', $ticker)) {
                        throw new \InvalidArgumentException('II_FEED_CONFIGURATION_INVALID');
                    }
                    SyncMarketUniverse::dispatch($owner, $ticker, $base['authorization_generation'] ?? null)->onConnection('database');
                    $this->info($ticker.'|II_MARKET_FEED=DISPATCH_REQUESTED');
                }
                return self::SUCCESS;
            }
            // Preserve the single-instrument command's existing output contract.
            $multi = !empty($base['universe_json']);
            if (!$multi) {
                $this->info('II_MARKET_FEED='.$ledger->run($entries[0], fn () => $feed->sync($entries[0])));
                return self::SUCCESS;
            }
            // A shared batch lock prevents overlapping universe runs, including
            // operator-selected runs. This is a per-run cap, not a daily quota.
                $failed = false;
                foreach ($entries as $entry) {
                    if (!$settings->runtimeAuthorized($base)) {
                        $this->error('II_FEED_AUTHORIZATION_REVOKED');
                        return self::FAILURE;
                    }
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
