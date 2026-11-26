<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MarketAcquisitionLedger
{
    public function reserve(array $cfg): void
    {
        $limit = filter_var($cfg['daily_request_limit'] ?? 10, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if (!$limit) {
            throw new \InvalidArgumentException('II_PROVIDER_DAILY_LIMIT_INVALID');
        }
        $day = now('UTC')->toDateString();
        $identity = ['provider' => 'twelve_data', 'utc_day' => $day];
        DB::transaction(function () use ($identity, $limit): void {
            DB::table('ii_market_request_days')->insertOrIgnore($identity + ['attempts' => 0]);
            $changed = DB::table('ii_market_request_days')->where($identity)->where('attempts', '<', $limit)->increment('attempts');
            if ($changed !== 1) {
                throw new \InvalidArgumentException('II_PROVIDER_DAILY_BUDGET_EXHAUSTED');
            }
        });
    }

    public function run(array $cfg, callable $operation): string
    {
        $owner = filter_var($cfg['owner_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $ticker = strtoupper(trim((string) ($cfg['ticker'] ?? '')));
        $provider = $cfg['provider'] ?? 'local';
        if (!$owner || !\App\Models\User::query()->whereKey($owner)->exists()
            || !preg_match('/^[A-Z0-9.\-]{1,16}$/D', $ticker)
            || !in_array($provider, ['local', 'twelve_data'], true)) {
            return $operation();
        }
        $id = (string) Str::uuid();
        DB::table('ii_market_acquisition_receipts')->insert([
            'id' => $id, 'user_id' => $owner, 'ticker' => $ticker,
            'provider' => $provider, 'status' => 'RUNNING', 'started_at' => now('UTC'),
        ]);
        try {
            $status = $operation();
        } catch (\Throwable $exception) {
            $code = $exception->getMessage();
            $this->finish($id, preg_match('/^II_[A-Z0-9_]{1,76}$/D', $code) ? $code : 'FAILED');
            throw $exception;
        }
        $this->finish($id, in_array($status, ['IMPORTED', 'UNCHANGED', 'DISABLED', 'WAITING_FOR_FEED'], true) ? $status : 'UNKNOWN');
        return $status;
    }

    /** Interrupted attempts remain uncertain: never infer that no data was written. */
    public function reconcileInterrupted(): int
    {
        return DB::table('ii_market_acquisition_receipts')
            ->where('status', 'RUNNING')->whereNull('completed_at')
            ->where('started_at', '<', now('UTC')->subHours(2))
            ->update(['status' => 'INTERRUPTED_OUTCOME_UNKNOWN', 'completed_at' => now('UTC')]);
    }

    private function finish(string $id, string $status): void
    {
        DB::table('ii_market_acquisition_receipts')->where('id', $id)->where('status', 'RUNNING')
            ->update(['status' => $status, 'completed_at' => now('UTC')]);
    }
}
