<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/** One-shot historical path, independent of scheduled provider discovery. */
final class HistoricalSignalBootstrap
{
    public function __construct(private readonly SignalInsightService $signals) {}

    public function run(int $owner, bool $rightsConfirmed, bool $persist, string $ticker = 'BBCA'): array
    {
        if (!$rightsConfirmed) {
            throw new \InvalidArgumentException('II_HISTORICAL_RIGHTS_CONFIRMATION_REQUIRED');
        }
        $blob = IdxHistoricalSnapshotSource::BLOBS[$ticker] ?? throw new \InvalidArgumentException('II_IDX_SNAPSHOT_TICKER_UNSUPPORTED');
        $settings = app(MarketFeedSettings::class);
        return Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($owner, $settings, $persist, $ticker, $blob): array {
            if ($owner < 1 || !$settings->canManage($owner) || (int) ($settings->current()['owner_id'] ?? 0) !== $owner) {
                throw new \InvalidArgumentException('II_HISTORICAL_OWNER_REQUIRED');
            }
            $cfg = $settings->current();
            if (($cfg['enabled'] ?? false) || ($cfg['discovery_enabled'] ?? false)) {
                throw new \InvalidArgumentException('II_HISTORICAL_STOP_AUTOMATION_FIRST');
            }
            return Cache::lock('ii-market-feed-'.$owner.'-'.$ticker, 120)->block(1, function () use ($owner, $settings, $cfg, $persist, $ticker, $blob): array {
                $previous = $this->signals->latestDocument($owner, $ticker);
                if ($persist && $previous !== null && (
                    ($previous['acquisition_mode'] ?? null) !== 'PINNED_HISTORICAL_SNAPSHOT'
                    || ($previous['source_blob'] ?? null) !== $blob
                    || ($previous['lineage_source_status'] ?? null) !== 'REGISTERED'
                )) {
                    throw new \InvalidArgumentException('II_HISTORICAL_EXISTING_SERIES_CONFLICT');
                }
                $snapshot = ($ticker === 'BBCA' ? app(HistoricalMarketSnapshotSource::class) : app()->make(IdxHistoricalSnapshotSource::class, ['ticker' => $ticker]))->snapshot();
                if (($snapshot['ticker'] ?? null) !== $ticker || ($snapshot['source_blob'] ?? null) !== $blob
                    || ($snapshot['provider_identity'] ?? null) !== $ticker.'@XIDX') {
                    throw new \InvalidArgumentException('II_IDX_SNAPSHOT_IDENTITY_MISMATCH');
                }
                if ($settings->current() !== $cfg || !$settings->canManage($owner)) {
                    throw new \InvalidArgumentException('II_HISTORICAL_AUTHORIZATION_CHANGED');
                }
                $document = array_merge($snapshot, [
                    'schema' => 'ii.signal.vs7.r1', 'kind' => 'user_supplied',
                    'imported_at' => now('Asia/Jakarta')->format('Y-m-d H:i:s.u T'),
                    'acquisition_mode' => 'PINNED_HISTORICAL_SNAPSHOT',
                    'rights_reference' => 'Explicit local confirmation: personal noncommercial research; source terms apply; no redistribution.',
                    'data_admission' => 'USER_IMPORTED_UNVERIFIED',
                    'decision' => 'HOLD', 'trading_authorized' => false,
                    'coverage_sessions' => count($snapshot['rows']),
                    'coverage_first_date' => $snapshot['rows'][0]['date'],
                    'coverage_last_date' => $snapshot['rows'][array_key_last($snapshot['rows'])]['date'],
                ], MarketSourceMetadataService::forImport([
                    'currency' => 'IDR', 'price_adjustment' => 'unknown', 'session_timezone' => 'Asia/Jakarta',
                ]));
                app(ByokIntakeFirewall::class)->assertIndependentSource($document);
                $insight = $this->signals->insight($document);
                if (!$insight['stale'] || $insight['trading_authorized'] || $insight['decision'] !== 'HOLD') {
                    throw new \InvalidArgumentException('II_HISTORICAL_RESEARCH_BOUNDARY_REQUIRED');
                }
                $status = 'PREVIEW';
                if ($persist) {
                    if ($previous !== null) {
                        if (($previous['source_sha256'] ?? null) !== $document['source_sha256']
                            || json_encode($previous['rows'], JSON_THROW_ON_ERROR) !== json_encode($document['rows'], JSON_THROW_ON_ERROR)) {
                            throw new \InvalidArgumentException('II_HISTORICAL_EXISTING_SERIES_CONFLICT');
                        }
                        $status = 'UNCHANGED';
                    } else {
                        $this->signals->save($owner, $ticker, $document);
                        $status = 'IMPORTED';
                    }
                    $stored = $this->signals->latestDocument($owner, $ticker);
                    if (($stored['lineage_source_status'] ?? null) !== 'REGISTERED'
                        || ($stored['source_sha256'] ?? null) !== $document['source_sha256']) {
                        throw new \RuntimeException('II_HISTORICAL_PERSISTENCE_UNVERIFIED');
                    }
                    $insight = $this->signals->insight($stored);
                }
                return ['status' => $status, 'source_commit' => $snapshot['source_commit'],
                    'source_blob' => $snapshot['source_blob'], 'source_sha256' => $snapshot['source_sha256'],
                    'source_rows' => $snapshot['source_rows'], 'first_date' => $document['coverage_first_date'],
                    'last_date' => $document['coverage_last_date'], 'rows' => $insight['rows'],
                    'ticker' => $insight['ticker'], 'price' => $insight['price'], 'sma20' => $insight['sma20'],
                    'sma50' => $insight['sma50'], 'rsi14' => $insight['rsi14'], 'atr14_pct' => $insight['atr14_pct'],
                    'state' => $insight['state'], 'decision' => $insight['decision'],
                    'trading_authorized' => $insight['trading_authorized']];
            });
        });
    }
}
