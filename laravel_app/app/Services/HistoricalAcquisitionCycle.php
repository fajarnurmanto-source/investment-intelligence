<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Explicit opt-in, bounded resumable acquisition of immutable public files only. */
final class HistoricalAcquisitionCycle
{
    public function __construct(private IdxHistoricalCatalog $catalog, private HistoricalSignalBootstrap $bootstrap) {}

    private function scope(): array
    {
        $entries = $this->catalog->entries();
        ksort($entries);
        return [array_keys($entries), hash('sha256', json_encode([IdxHistoricalSnapshotSource::COMMIT, IdxHistoricalSnapshotSource::BLOBS, $entries], JSON_THROW_ON_ERROR))];
    }

    private function generation(int $owner): string
    {
        $settings = app(MarketFeedSettings::class);
        $cfg = $settings->current();
        if ($owner < 1 || (int) ($cfg['owner_id'] ?? 0) !== $owner || !$settings->canManage($owner)
            || ($cfg['enabled'] ?? false) || ($cfg['discovery_enabled'] ?? false)
            || empty($cfg['authorization_generation'])) {
            throw new \InvalidArgumentException('II_CYCLE_OWNER_OR_POLICY_INVALID');
        }
        return $cfg['authorization_generation'];
    }

    public function enable(int $owner, bool $rights): void
    {
        if (!$rights) {
            throw new \InvalidArgumentException('II_CYCLE_RIGHTS_REQUIRED');
        }
        Cache::lock('ii-historical-cycle-'.$owner, 1800)->block(1, function () use ($owner): void {
            [, $hash] = $this->scope();
            $generation = $this->generation($owner);
            $row = DB::table('ii_historical_cycles')->where('user_id', $owner)->first();
            if ($row && $row->manifest_hash !== $hash) {
                throw new \InvalidArgumentException('II_CYCLE_MANIFEST_CHANGED');
            }
            DB::table('ii_historical_cycles')->updateOrInsert(['user_id' => $owner], [
                'manifest_hash' => $hash, 'generation' => $generation, 'enabled' => true,
                'state' => 'READY', 'attempts' => 0, 'next_attempt_at' => null, 'last_code' => null,
                'created_at' => $row->created_at ?? now('UTC'), 'updated_at' => now('UTC'),
            ]);
        });
    }

    public function stop(int $owner): void
    {
        Cache::lock('ii-historical-cycle-'.$owner, 1800)->block(1, function () use ($owner): void {
            $this->generation($owner);
            DB::table('ii_historical_cycles')->where('user_id', $owner)->update(['enabled' => false, 'state' => 'PAUSED', 'updated_at' => now('UTC')]);
        });
    }

    public function status(int $owner): array
    {
        $this->generation($owner);
        return (array) DB::table('ii_historical_cycles')->where('user_id', $owner)->first();
    }

    private function readback(int $owner): array
    {
        $row = DB::table('ii_historical_cycles')->where('user_id', $owner)->first();
        if ($row === null) {
            throw new \RuntimeException('II_CYCLE_CHECKPOINT_READBACK_FAILED');
        }
        return (array) $row;
    }

    public function tick(int $owner, int $limit = 5): array
    {
        if ($limit < 1 || $limit > 5) {
            throw new \InvalidArgumentException('II_CYCLE_LIMIT_INVALID');
        }
        return Cache::lock('ii-historical-cycle-'.$owner, 1800)->block(1, function () use ($owner, $limit): array {
            [$tickers, $hash] = $this->scope();
            $query = fn () => DB::table('ii_historical_cycles')->where('user_id', $owner);
            $row = $query()->first();
            if (!$row || !$row->enabled) {
                return ['state' => 'DISABLED'];
            }
            try {
                $generation = $this->generation($owner);
            } catch (\InvalidArgumentException) {
                $query()->update(['enabled' => false, 'state' => 'POLICY_PAUSED', 'updated_at' => now('UTC')]);
                return ['state' => 'POLICY_PAUSED'];
            }
            if ($row->manifest_hash !== $hash || $row->generation !== $generation) {
                $query()->update(['enabled' => false, 'state' => 'POLICY_PAUSED', 'updated_at' => now('UTC')]);
                return ['state' => 'POLICY_PAUSED'];
            }
            if ($row->next_attempt_at && now('UTC')->lt(\Illuminate\Support\Carbon::parse($row->next_attempt_at, 'UTC'))) {
                return ['state' => 'BACKOFF', 'cursor' => $row->cursor];
            }
            for ($i = 0; $i < $limit && $row->cursor < count($tickers); $i++) {
                $ticker = $tickers[$row->cursor];
                $code = null;
                $sha = null;
                try {
                    if ($this->generation($owner) !== $generation) {
                        throw new \InvalidArgumentException('II_CYCLE_OWNER_OR_POLICY_INVALID');
                    }
                    // A registered, intact archive from the pinned source needs no new request.
                    $doc = app(SignalInsightService::class)->latestDocument($owner, $ticker);
                    if ($doc && ($doc['lineage_source_status'] ?? null) === 'REGISTERED'
                        && app(MarketSourceRegistry::class)->describe($doc) === 'idx_pinned'
                        && preg_match('/^[a-f0-9]{64}$/D', $doc['source_sha256'] ?? '')
                        && app(SignalInsightService::class)->insight($doc)['state'] === 'STALE') {
                        $status = 'LOCAL_VERIFIED';
                        $sha = $doc['source_sha256'];
                    } else {
                        $result = $this->bootstrap->run($owner, true, true, $ticker);
                        if (!in_array($result['status'], ['IMPORTED', 'UNCHANGED'], true)) {
                            throw new \RuntimeException('II_CYCLE_SOURCE_UNAVAILABLE');
                        }
                        $status = $result['status'];
                        $sha = $result['source_sha256'];
                    }
                } catch (\Throwable $e) {
                    $code = $e->getMessage();
                    // Only deterministic data failures advance the cursor. Unknown errors
                    // stop the cycle; never discard a candidate on a transient fault.
                    if (in_array($code, ['II_IDX_SNAPSHOT_COVERAGE_MISMATCH','II_IDX_SNAPSHOT_SHARE_BASIS_CHANGED',
                        'II_IDX_SNAPSHOT_OHLCV_INVALID','II_IDX_SNAPSHOT_NUMBER_INVALID','II_IDX_SNAPSHOT_DATE_INVALID',
                        'II_IDX_SNAPSHOT_INSUFFICIENT_HISTORY','II_IDX_SNAPSHOT_ROW_INVALID','II_IDX_SNAPSHOT_HEADER_INVALID',
                        'II_HISTORICAL_EXISTING_SERIES_CONFLICT'], true)) {
                        $status = 'REJECTED';
                    } else {
                        $retry = in_array($code, ['II_IDX_SNAPSHOT_NETWORK_ERROR','II_IDX_SNAPSHOT_TEMPORARY_ERROR','II_CYCLE_SOURCE_UNAVAILABLE'], true);
                        $attempts = $row->attempts + 1;
                        $query()->update(['attempts' => $attempts, 'state' => $retry ? 'BACKOFF' : 'REVIEW_REQUIRED',
                            'enabled' => $retry, 'last_code' => preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_CYCLE_UNKNOWN_ERROR',
                            'next_attempt_at' => $retry ? now('UTC')->addMinutes(min(360, 15 * (2 ** min(5, $attempts - 1)))) : null,
                            'updated_at' => now('UTC')]);
                        return $this->readback($owner);
                    }
                }
                DB::transaction(function () use ($query, $row, $owner, $hash, $ticker, $status, $code, $sha): void {
                    DB::table('ii_historical_cycle_receipts')->updateOrInsert(
                        ['user_id' => $owner, 'manifest_hash' => $hash, 'ticker' => $ticker],
                        ['status' => $status, 'code' => $code, 'source_sha256' => $sha, 'updated_at' => now('UTC')]
                    );
                    $query()->update(['cursor' => $row->cursor + 1,
                        'accepted' => $row->accepted + (int) ($status !== 'REJECTED'),
                        'rejected' => $row->rejected + (int) ($status === 'REJECTED'),
                        'attempts' => 0, 'state' => 'RUNNING', 'last_code' => $code,
                        'next_attempt_at' => null, 'updated_at' => now('UTC')]);
                });
                $row = (object) $this->readback($owner);
            }
            if ($row->cursor >= count($tickers)) {
                $query()->update(['enabled' => false, 'state' => 'COMPLETE', 'updated_at' => now('UTC')]);
            }
            return (array) $query()->first();
        });
    }
}
