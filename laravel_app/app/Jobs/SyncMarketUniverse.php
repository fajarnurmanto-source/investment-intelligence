<?php

namespace App\Jobs;

use App\Services\LicensedMarketFeedService;
use App\Services\MarketAcquisitionLedger;
use App\Services\MarketUniverseRegistry;
use App\Services\MarketFeedSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class SyncMarketUniverse implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 3600;

    public ?string $authorizationGeneration = null;

    public function __construct(public readonly int $ownerId, public readonly string $ticker, ?string $authorizationGeneration = null)
    {
        $this->authorizationGeneration = $authorizationGeneration;
    }

    public function uniqueId(): string
    {
        return $this->ownerId.'|'.$this->ticker.'|'.($this->authorizationGeneration ?? 'legacy');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(MarketUniverseRegistry $registry, LicensedMarketFeedService $feed, MarketAcquisitionLedger $ledger): void
    {
        try {
            Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($registry, $feed, $ledger): void {
                $base = app(MarketFeedSettings::class)->current();
                if (($base['enabled'] ?? false) !== true || (int) ($base['owner_id'] ?? 0) !== $this->ownerId) {
                    return;
                }
                if (($base['authorization_generation'] ?? null) !== $this->authorizationGeneration) {
                    return;
                }
                foreach ($registry->entries($base) as $entry) {
                    if (strtoupper(trim((string) ($entry['ticker'] ?? ''))) !== $this->ticker) {
                        continue;
                    }
                    $ledger->run($entry, function () use ($entry, $feed): string {
                        $provider = $entry['provider'] ?? 'local';
                        $pause = 'ii-market-provider-pause-'.$provider;
                        if (Cache::has($pause)) {
                            throw new \InvalidArgumentException('II_PROVIDER_COOLDOWN_ACTIVE');
                        }
                        try {
                            return $feed->sync($entry);
                        } catch (\InvalidArgumentException $exception) {
                            if (in_array($exception->getMessage(), ['II_PROVIDER_RATE_LIMITED', 'II_PROVIDER_ENTITLEMENT_DENIED', 'II_PROVIDER_CREDENTIAL_UNAVAILABLE'], true)) {
                                Cache::put($pause, true, 900);
                            }
                            throw $exception;
                        }
                    });
                    return;
                }
            });
        } catch (\InvalidArgumentException $exception) {
            // Permanent validation/rights/quota failures are recorded, not retried.
            $this->fail(new \RuntimeException('II_MARKET_JOB_REJECTED'));
        } catch (\Throwable $exception) {
            if (in_array($exception->getMessage(), ['II_PROVIDER_NETWORK_ERROR', 'II_PROVIDER_TEMPORARY_ERROR'], true)
                || $exception instanceof \Illuminate\Contracts\Cache\LockTimeoutException) {
                throw new \RuntimeException('II_MARKET_JOB_TRANSIENT');
            }
            $this->fail(new \RuntimeException('II_MARKET_JOB_FAILED'));
        }
    }
}
