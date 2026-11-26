<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/** Provider observations are advisory; the persistent daily ledger remains mandatory. */
final class MarketProviderCreditGuard
{
    private function key(int $owner): string
    {
        return 'ii-twelve-data-credit-'.$owner;
    }

    public function remaining(int $owner): ?int
    {
        $value = Cache::get($this->key($owner));
        return is_int($value) && $value >= 0 ? $value : null;
    }

    public function assertAvailable(int $owner): void
    {
        if ($this->remaining($owner) === 0) {
            throw new \InvalidArgumentException('II_PROVIDER_RATE_LIMITED');
        }
    }

    public function observe(int $owner, Response $response): void
    {
        if ($response->status() === 429) {
            Cache::put($this->key($owner), 0, 60);
            return;
        }
        $raw = $response->header('api-credits-left');
        if (!is_string($raw) || !preg_match('/^[0-9]{1,9}$/D', $raw)) {
            return;
        }
        // A conservative rolling minute, not a claim about provider reset time.
        Cache::put($this->key($owner), (int) $raw, 60);
    }
}
