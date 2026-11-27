<?php

namespace App\Services;

/** Local-only operational read model. Never probes providers or asserts entitlement. */
final class ResearchContinuityService
{
    public function overview(int $owner): array
    {
        $documents = app(SignalInsightService::class)->latestDocuments($owner);
        $items = [];
        foreach ($documents as $ticker => $document) {
            if (($document['kind'] ?? '') === 'synthetic') {
                continue;
            }
            $insight = app(SignalInsightService::class)->insight($document);
            $items[] = [
                'ticker' => $ticker,
                'sessions' => $insight['rows'],
                'last_date' => $insight['last_date'],
                'freshness' => $insight['stale'] ? 'STALE' : 'RECENT_UNVERIFIED',
                'lineage' => $document['lineage_source_status'] ?? 'UNREGISTERED',
                'basis' => $insight['price_adjustment'],
                'mode' => $document['acquisition_mode'] ?? 'USER_SUPPLIED',
                'source' => $document['source_name'] ?? 'UNKNOWN',
            ];
        }
        usort($items, fn ($a, $b) => strcmp($a['ticker'], $b['ticker']));
        return [
            'mode' => $items === [] ? 'NO_LOCAL_RESEARCH_DATA' : 'LOCAL_RESEARCH_AVAILABLE',
            'instruments' => $items,
            'recent' => count(array_filter($items, fn ($item) => $item['freshness'] === 'RECENT_UNVERIFIED')),
            'registered' => count(array_filter($items, fn ($item) => $item['lineage'] === 'REGISTERED')),
            'live_provider_status' => 'NOT_PROBED',
        ];
    }
}
