<?php

namespace App\Services;

/** Bounded, read-only audit of the latest distinct registered source versions. */
final class MarketAccuracyOverview
{
    public function overview(int $owner): array
    {
        $groups = [];
        $registry = app(MarketSourceRegistry::class);
        foreach (app(SignalInsightService::class)->validVersions($owner) as $document) {
            $source = $registry->describe($document);
            if ($source === null || ($document['lineage_source_status'] ?? null) !== 'REGISTERED') {
                continue;
            }
            $ticker = $document['ticker'];
            $old = $groups[$ticker][$source] ?? null;
            if ($old === null || strcmp((string) $document['imported_at'], (string) $old['imported_at']) > 0) {
                $groups[$ticker][$source] = $document;
            }
        }
        $items = [];
        foreach ($groups as $ticker => $sources) {
            $docs = array_values($sources);
            $comparisons = [];
            for ($i = 0; $i < count($docs); $i++) {
                for ($j = $i + 1; $j < count($docs); $j++) {
                    $comparisons[] = app(MarketSeriesReconciliation::class)->compare($docs[$i], $docs[$j]);
                }
            }
            $items[] = ['ticker' => $ticker, 'sources' => count($docs), 'comparisons' => $comparisons,
                'status' => count($docs) < 2 ? 'SINGLE_SOURCE_UNVERIFIED'
                    : (in_array('CONFLICT', array_column($comparisons, 'status'), true) ? 'CONFLICT' : 'REVIEW_REQUIRED')];
        }
        usort($items, fn ($a, $b) => strcmp($a['ticker'], $b['ticker']));
        return ['registry' => $registry->entries(), 'items' => $items];
    }
}
