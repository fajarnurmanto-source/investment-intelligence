<?php

namespace App\Services;

/** Candidate catalog, not a grant of rights or a claim of provider availability. */
final class MarketSourceRegistry
{
    public function entries(): array
    {
        return [
            'idx_pinned' => ['name' => 'Dataset-Saham-IDX', 'upstream' => 'IDX', 'capability' => 'HISTORICAL_EOD', 'status' => 'IMPLEMENTED_PINNED', 'rights' => 'NONCOMMERCIAL_ATTESTATION_REQUIRED'],
            'twelve_data' => ['name' => 'Twelve Data', 'upstream' => null, 'capability' => 'DIRECT_EOD', 'status' => 'IMPLEMENTED_LIVE_UNVERIFIED', 'rights' => 'ACCOUNT_POLICY_REQUIRED'],
            'aethereal' => ['name' => 'Aethereal IDX', 'upstream' => 'YAHOO_FINANCE', 'capability' => 'EOD_DIAGNOSTIC', 'status' => 'UPSTREAM_RIGHTS_PENDING', 'rights' => 'NOT_CLEARED'],
            'seoultra' => ['name' => 'Seoultra IDX', 'upstream' => 'YAHOO_FINANCE', 'capability' => 'PINNED_EOD_DIAGNOSTIC', 'status' => 'UPSTREAM_RIGHTS_PENDING', 'rights' => 'NOT_CLEARED'],
            'idx_primary_document' => ['name' => 'IDX primary document', 'upstream' => 'IDX', 'capability' => 'LOCAL_EVIDENCE_TRANSCRIPTION', 'status' => 'AUTHENTICITY_REVIEW_REQUIRED', 'rights' => 'USER_ATTESTATION_REQUIRED'],
            'yahoo' => ['name' => 'Yahoo Finance', 'upstream' => 'YAHOO_FINANCE', 'capability' => 'EOD_CANDIDATE', 'status' => 'CANDIDATE', 'rights' => 'NOT_ASSESSED'],
            'msn' => ['name' => 'MSN Money', 'upstream' => null, 'capability' => 'EOD_CANDIDATE', 'status' => 'CANDIDATE', 'rights' => 'NOT_ASSESSED'],
            'alltick' => ['name' => 'AllTick', 'upstream' => null, 'capability' => 'EOD_CANDIDATE', 'status' => 'CANDIDATE', 'rights' => 'NOT_ASSESSED'],
            'itick' => ['name' => 'iTick', 'upstream' => null, 'capability' => 'EOD_CANDIDATE', 'status' => 'CANDIDATE', 'rights' => 'NOT_ASSESSED'],
            'idx_ksei' => ['name' => 'IDX / KSEI', 'upstream' => null, 'capability' => 'CORPORATE_ACTION_OWNERSHIP_CANDIDATE', 'status' => 'CANDIDATE', 'rights' => 'NOT_ASSESSED'],
            'tradingview' => ['name' => 'TradingView', 'upstream' => null, 'capability' => 'STREAMING_CANDIDATE', 'status' => 'CANDIDATE', 'rights' => 'NOT_ASSESSED'],
            'finnhub' => ['name' => 'Finnhub', 'upstream' => null, 'capability' => 'EOD_CANDIDATE', 'status' => 'CANDIDATE', 'rights' => 'NOT_ASSESSED'],
        ];
    }

    public function describe(array $document): ?string
    {
        $identity = $document['provider_identity'] ?? null;
        $blob = null;
        if (($document['acquisition_mode'] ?? null) === 'PINNED_HISTORICAL_SNAPSHOT') {
            try {
                $blob = app(IdxHistoricalCatalog::class)->resolve((string) ($document['ticker'] ?? ''))['blob'];
            } catch (\InvalidArgumentException) {
                return null;
            }
        }
        if (($document['acquisition_mode'] ?? null) === 'PINNED_HISTORICAL_SNAPSHOT'
            && ($document['source_commit'] ?? null) === IdxHistoricalSnapshotSource::COMMIT
            && ($document['source_blob'] ?? null) === $blob
            && $identity === ($document['ticker'] ?? '').'@XIDX') {
            return 'idx_pinned';
        }
        if (($document['acquisition_mode'] ?? null) === 'LICENSED_PROVIDER_API'
            && ($document['source_name'] ?? null) === 'Twelve Data'
            && ($document['source_url'] ?? null) === TwelveDataDailyAdapter::URL) {
            return 'twelve_data';
        }
        return null;
    }
}
