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
        if (is_int($value) && $value >= 0) {
            return $value; // Existing short-lived observations.
        }
        return is_array($value) && ($value['expires'] ?? 0) > now()->timestamp
            && is_int($value['remaining'] ?? null) ? max(0, $value['remaining']) : null;
    }

    public function assertAvailable(int $owner): void
    {
        if ($this->remaining($owner) === 0) {
            throw new \InvalidArgumentException('II_PROVIDER_RATE_LIMITED');
        }
    }

    /** Call after persistent reservation, under the shared operation lock. */
    public function debit(int $owner): void
    {
        $value = Cache::get($this->key($owner));
        if (!is_array($value) || ($value['expires'] ?? 0) <= now()->timestamp) {
            return;
        }
        $value['remaining'] = max(0, (int) $value['remaining'] - 1);
        Cache::put($this->key($owner), $value, max(1, $value['expires'] - now()->timestamp));
    }

    private function record(int $owner, int $remaining): void
    {
        Cache::put($this->key($owner), ['remaining' => $remaining, 'expires' => now()->timestamp + 60], 60);
    }

    public function markExhausted(int $owner): void
    {
        $this->record($owner, 0);
    }

    public function observe(int $owner, Response $response): void
    {
        if ($response->status() === 429) {
            $this->markExhausted($owner);
            return;
        }
        $raw = $response->header('api-credits-left');
        if (!is_string($raw) || !preg_match('/^[0-9]{1,9}$/D', $raw)) {
            return;
        }
        // A conservative rolling minute, not a claim about provider reset time.
        $this->record($owner, (int) $raw);
    }
}
