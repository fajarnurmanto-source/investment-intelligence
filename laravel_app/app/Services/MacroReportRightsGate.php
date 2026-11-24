<?php

namespace App\Services;

use App\Models\EconomicObservation;
use App\Models\IngestionRun;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
            // Cross-check the persisted observation against its ingestion
            // receipt, not merely a mutable series label or source URL.
            $run = IngestionRun::query()->whereKey($latest->ingestion_run_id)->first();
            if ($run === null
                || $run->status !== 'SUCCEEDED'
                || $run->source_id !== 'FRED'
                || $run->series_id !== $id
                || ! hash_equals((string) $run->response_sha256, $latest->raw_checksum)) {
                $issues[] = $id.':INGESTION_RECEIPT_MISMATCH';
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
                && ($approval['source_owner'] ?? null) === $metadata['owner']
                && $this->verifiedLocalReceipt($approval);
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
    private function verifiedLocalReceipt(array $approval): bool
    {
        // Receipt filename is deployment controlled, never accepted from an
        // HTTP request. Keep the file out of Git and out of the installer.
        $filename = $approval['receipt_file'] ?? null;
        if (! is_string($filename)
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,95}$/D', $filename)
            || str_contains($filename, '..')) {
            return false;
        }
        $path = 'ii-rights-reviews/'.$filename;
        $disk = Storage::disk('local');
        if (! $disk->exists($path)
            || $disk->size($path) < 1
            || $disk->size($path) > 2_000_000) {
            return false;
        }

        return hash_equals(
            (string) ($approval['receipt_sha256'] ?? ''),
            hash('sha256', $disk->get($path))
        );
    }

}
