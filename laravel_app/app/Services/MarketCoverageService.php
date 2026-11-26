<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class MarketCoverageService
{
    public function overview(int $owner, array $cfg): array
    {
        if ((int) ($cfg['owner_id'] ?? 0) !== $owner) {
            return ['status' => 'NOT_CONFIGURED', 'instruments' => [], 'receipts' => []];
        }
        $status = ($cfg['enabled'] ?? false) === true ? 'ENABLED' : 'DISABLED';
        try {
            $entries = app(MarketUniverseRegistry::class)->entries($cfg);
        } catch (\Throwable) {
            return ['status' => 'CONFIGURATION_INVALID', 'instruments' => [], 'receipts' => []];
        }
        $instruments = [];
        foreach ($entries as $entry) {
            $ticker = strtoupper(trim((string) ($entry['ticker'] ?? '')));
            if (!preg_match('/^[A-Z0-9.\-]{1,16}$/D', $ticker)) {
                continue;
            }
            $doc = app(SignalInsightService::class)->latestDocument($owner, $ticker);
            $provider = $entry['provider'] ?? 'local';
            $expectedMode = $provider === 'twelve_data' ? 'LICENSED_PROVIDER_API' : 'LICENSED_LOCAL_FEED';
            $expectedIdentity = $provider === 'twelve_data'
                ? strtoupper(trim($entry['provider_symbol'] ?? '')).'@'.strtoupper(trim($entry['provider_mic'] ?? '')) : null;
            $matches = $doc !== null && ($doc['acquisition_mode'] ?? null) === $expectedMode
                && ($doc['provider_identity'] ?? null) === $expectedIdentity
                && ($doc['source_name'] ?? null) === ($entry['source_name'] ?? null)
                && ($doc['source_url'] ?? null) === ($entry['source_url'] ?? null)
                && ($doc['rights_reference'] ?? null) === ($entry['rights_reference'] ?? null);
            $rows = $matches ? $doc['rows'] : [];
            $first = $rows[0]['date'] ?? null;
            $last = $rows[array_key_last($rows)]['date'] ?? null;
            $age = null;
            $gaps = 0;
            try {
                $zone = new \DateTimeZone($entry['session_timezone'] ?? 'UTC');
                if ($last !== null) {
                    $date = new \DateTimeImmutable($last, $zone);
                    $age = (int) $date->diff(new \DateTimeImmutable('today', $zone))->format('%r%a');
                    for ($i = 1; $i < count($rows); $i++) {
                        $a = new \DateTimeImmutable($rows[$i - 1]['date'], $zone);
                        $z = new \DateTimeImmutable($rows[$i]['date'], $zone);
                        if ($a->diff($z)->days > 7) {
                            $gaps++;
                        }
                    }
                }
            } catch (\Throwable) {
                $age = null;
            }
            $instruments[] = [
                'ticker' => $ticker, 'provider' => $provider,
                'first' => $first, 'last' => $last, 'sessions' => count($rows),
                'target' => (int) ($entry['history_sessions'] ?? 250),
                'age_days' => $age, 'long_calendar_gaps' => $gaps,
                'status' => !$matches ? ($doc ? 'SOURCE_MISMATCH' : 'NO_DATA')
                    : (($doc['lineage_source_status'] ?? null) !== 'REGISTERED' ? 'LINEAGE_UNVERIFIED'
                    : ($age === null || $age < 0 || $age > 10 ? 'STALE_OR_INVALID' : 'RECENT_UNVERIFIED')),
            ];
        }
        return ['status' => $status, 'instruments' => $instruments,
            'receipts' => DB::table('ii_market_acquisition_receipts')->where('user_id', $owner)
                ->orderByDesc('started_at')->limit(50)->get(['ticker','provider','status','started_at','completed_at'])->all()];
    }
}
