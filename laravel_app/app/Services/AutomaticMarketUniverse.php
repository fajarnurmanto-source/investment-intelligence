<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class AutomaticMarketUniverse
{
    /** Read-only proposal. Does not activate acquisition or establish entitlement. */
    public function propose(int $owner, array $policy, array $mics): array
    {
        $limit = filter_var($policy['request_budget'] ?? 10, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 50]]);
        $daily = filter_var($policy['daily_request_limit'] ?? 10, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if (!$limit || !$daily || $owner < 1 || (int) ($policy['owner_id'] ?? 0) !== $owner
            || ($policy['provider'] ?? null) !== 'twelve_data'
            || ($policy['storage_permitted'] ?? false) !== true
            || ($policy['automation_permitted'] ?? false) !== true
            || trim((string) ($policy['rights_reference'] ?? '')) === ''
            || $mics === [] || count($mics) > 10) {
            throw new \InvalidArgumentException('II_UNIVERSE_POLICY_INVALID');
        }
        foreach ($mics as $mic) {
            if (!is_string($mic) || !preg_match('/^[A-Z0-9]{4}$/D', $mic)) {
                throw new \InvalidArgumentException('II_UNIVERSE_SCOPE_INVALID');
            }
        }
        $used = (int) DB::table('ii_market_request_days')->where('provider', 'twelve_data')
            ->where('utc_day', now('UTC')->toDateString())->value('attempts');
        $credits = app(MarketProviderCreditGuard::class)->remaining($owner);
        $cap = min($limit, max(0, $daily - $used), $credits ?? PHP_INT_MAX);
        $base = DB::table('ii_market_security_master')->where('user_id', $owner)->where('provider', 'twelve_data');
        $ambiguous = (clone $base)->select('symbol')->groupBy('symbol')->havingRaw('COUNT(*) > 1');
        $eligible = (clone $base)->whereIn('mic', $mics)
            ->where('status', 'CATALOG_ONLY_ENTITLEMENT_UNVERIFIED')
            ->where('observed_at', '>=', now('UTC')->subDays(7))
            ->whereNotIn('symbol', $ambiguous);
        // Fair rotation: least recently attempted first; never infer investment merit.
        $lastAttempt = DB::table('ii_market_acquisition_receipts')->select('ticker')
            ->selectRaw('MAX(started_at) as last_attempt')->where('user_id', $owner)
            ->where('provider', 'twelve_data')->groupBy('ticker');
        $rows = $eligible->leftJoinSub($lastAttempt, 'attempts', 'ii_market_security_master.symbol', '=', 'attempts.ticker')
            ->select('ii_market_security_master.*')->orderByRaw('CASE WHEN attempts.last_attempt IS NULL THEN 0 ELSE 1 END')
            ->orderBy('attempts.last_attempt')->orderBy('mic')->orderBy('symbol')
            ->limit(500)->get();
        $entries = [];
        $blocked = 0;
        foreach ($rows as $row) {
            if (count($entries) >= $cap) {
                break;
            }
            $doc = app(SignalInsightService::class)->latestDocument($owner, $row->symbol);
            if ($doc !== null && (($doc['provider_identity'] ?? null) !== $row->symbol.'@'.$row->mic
                || ($doc['currency'] ?? null) !== $row->currency
                || ($doc['session_timezone'] ?? null) !== $row->session_timezone
                || ($doc['price_adjustment'] ?? null) !== 'unadjusted')) {
                $blocked++;
                continue;
            }
            $entries[] = ['ticker' => $row->symbol, 'provider_symbol' => $row->symbol,
                'provider_mic' => $row->mic, 'currency' => $row->currency,
                'session_timezone' => $row->session_timezone, 'price_adjustment' => 'unadjusted'];
        }
        return ['status' => 'PROPOSAL_ENTITLEMENT_UNVERIFIED', 'entries' => $entries,
            'remaining_local_requests' => max(0, $daily - $used), 'observed_provider_credits' => $credits, 'legacy_conflicts' => $blocked,
            'selection_method' => 'LEAST_RECENTLY_ATTEMPTED_BOUNDED_SCAN'];
    }
}
