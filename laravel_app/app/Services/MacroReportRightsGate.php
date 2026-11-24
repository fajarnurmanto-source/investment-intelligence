<?php

namespace App\Services;

use App\Models\EconomicObservation;
use Illuminate\Support\Facades\Schema;

/**
 * Strict pre-download review of independent FRED series.
 *
 * FRED transport provenance alone does not confer redistribution rights.
 * The review receipts are deployment-controlled and intentionally absent by
 * default. No runtime URL query/checkbox can upgrade them.
 */
final class MacroReportRightsGate
{
    public function preflight(array $dashboard): array
    {
        $issues = [];
        $sources = [];
        if (! Schema::hasTable('ii_economic_observations') || ! is_array($dashboard['series'] ?? null)) {
            return ['status' => 'DENIED', 'issues' => ['MIGRATION_OR_SERIES_UNAVAILABLE'], 'sources' => []];
        }
        if ($dashboard['series'] === []) {
            return ['status' => 'DENIED', 'issues' => ['NO_REPORT_SERIES'], 'sources' => []];
        }

        foreach ($dashboard['series'] as $series) {
            $id = (string) ($series['id'] ?? '');
            $metadata = config('ii.fred.series.'.$id);
            if (! is_array($metadata) || ! preg_match('/^[A-Z0-9]{3,24}$/D', $id)) {
                $issues[] = $id.':SERIES_NOT_ALLOWLISTED';
                continue;
            }
            $latest = EconomicObservation::query()
                ->where('source_id', 'FRED')->where('series_id', $id)
                ->orderByDesc('observed_on')->first();
            if ($latest === null
                || ! is_string($latest->raw_checksum)
                || ! preg_match('/^[a-f0-9]{64}$/D', $latest->raw_checksum)
                || ($latest->quality_status !== 'VALID' && $latest->quality_status !== 'VALID_WITH_SOURCE_GAPS')
                || $latest->retrieved_at === null
                || ($series['latest_date'] ?? null) !== $latest->observed_on->format('Y-m-d')
                || (string) ($series['latest_value'] ?? '') !== (string) (float) $latest->value
            ) {
                $issues[] = $id.':OBSERVATION_PROVENANCE_MISMATCH';
                continue;
            }
            $url = (string) ($latest->source_url ?? '');
            $parts = parse_url($url);
            if (! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ($parts['host'] ?? null) !== 'fred.stlouisfed.org'
                || isset($parts['user']) || isset($parts['pass'])) {
                $issues[] = $id.':UNTRUSTED_SOURCE_URL';
                continue;
            }
            $approval = config('ii.fred.report_rights.'.$id, []);
            $approved = is_array($approval)
                && ($approval['status'] ?? null) === 'REVIEWED_ALLOWED'
                && ($approval['scope'] ?? null) === 'LOCAL_PERSONAL_NONCOMMERCIAL_CSV'
                && is_string($approval['receipt_sha256'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/D', $approval['receipt_sha256'])
                && ($approval['source_owner'] ?? null) === $metadata['owner'];
            if (! $approved) {
                $issues[] = $id.':RIGHTS_REVIEW_REQUIRED';
            }
            $sources[] = [
                'series_id' => $id,
                'source_owner' => (string) $metadata['owner'],
                'source_host' => 'fred.stlouisfed.org',
                'response_sha256' => $latest->raw_checksum,
                'rights_status' => $approved ? 'REVIEWED_ALLOWED_FOR_LOCAL_NONCOMMERCIAL' : 'NOT_CLEARED',
                'review_receipt_sha256' => $approved ? $approval['receipt_sha256'] : null,
            ];
        }

        return [
            'status' => $issues === [] ? 'REVIEWED_ALLOWED_LOCAL_NONCOMMERCIAL' : 'DENIED',
            'issues' => $issues,
            'sources' => $sources,
            'terms_url' => (string) config('ii.fred.terms_url'),
        ];
    }
}
