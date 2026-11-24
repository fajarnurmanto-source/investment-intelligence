<?php

namespace App\Services;

/**
 * Digest-only manifest for a policy-reviewed FRED personal CSV. The review
 * receipt is controlled by local deployment configuration. A registry claim
 * does not itself grant export rights.
 */
final class MacroReportLineageService
{
    public function attest(int $ownerId, array $rights, string $csv): array
    {
        if (($rights['status'] ?? null) !== 'REVIEWED_ALLOWED_LOCAL_NONCOMMERCIAL'
            || ! is_array($rights['sources'] ?? null)
            || $rights['sources'] === []) {
            throw new \RuntimeException('II_MACRO_REPORT_RIGHTS_NOT_CLEARED');
        }
        $registry = app(LocalLineageRegistry::class);
        $parents = [];
        foreach ($rights['sources'] as $source) {
            if (($source['rights_status'] ?? '') !== 'REVIEWED_ALLOWED_FOR_LOCAL_NONCOMMERCIAL'
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($source['review_receipt_sha256'] ?? ''))
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($source['response_sha256'] ?? ''))) {
                throw new \RuntimeException('II_MACRO_REPORT_SOURCE_NOT_CLEARED');
            }
            $seriesId = (string) $source['series_id'];
            $fingerprint = hash('sha256', implode('|', [
                'FRED', $seriesId, $source['response_sha256'], $source['review_receipt_sha256'],
            ]));
            $parent = $registry->registerSource(
                $ownerId, 'fred_reviewed_series', $fingerprint, $fingerprint,
                [
                    'source_name' => 'FRED_'.$seriesId,
                    'publisher' => $source['source_owner'],
                    'source_url' => 'https://fred.stlouisfed.org/series/'.$seriesId,
                    'terms_url' => (string) config('ii.fred.terms_url'),
                    'license_status' => 'review_receipt_required',
                    'usage_rights' => 'local_personal_noncommercial_csv',
                ]
            );
            if (! $registry->verifyTrace($ownerId, $parent['id'])) {
                throw new \RuntimeException('II_MACRO_REPORT_PARENT_INTEGRITY_FAILED');
            }
            $parents[] = $parent['id'];
        }
        $outputSha = hash('sha256', $csv);
        $ref = hash('sha256', json_encode([
            'fred.local.personal.report.v1', $parents, $outputSha,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $report = $registry->registerTransformation(
            $ownerId, 'fred_personal_report', $ref,
            'RENDER_FRED_PERSONAL_CSV_V1', $outputSha, $parents
        );
        if (! $registry->verifyTrace($ownerId, $report['id'])
            || $report['rights_basis'] === 'RESTRICTED_BYOK') {
            throw new \RuntimeException('II_MACRO_REPORT_INTEGRITY_FAILED');
        }

        // This is a narrow, explicitly reviewed local-personal exception.
        // The registry's generic can_export=false MUST NOT be interpreted as
        // a broader redistributable or commercial license.
        return ['node_id' => $report['id'], 'content_sha256' => $outputSha];
    }
}
