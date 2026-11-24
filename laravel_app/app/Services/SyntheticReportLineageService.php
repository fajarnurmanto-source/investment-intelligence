<?php

namespace App\Services;

/**
 * Audits downloadable reports that use the application's generated synthetic
 * fixtures only. This is NOT export clearance for external market data.
 *
 * Registry stores the rendered payload SHA-256, not report text, metrics or
 * reviewer comments. Source and report transform remain research-only.
 */
final class SyntheticReportLineageService
{
    public function attest(
        int $ownerId,
        string $reportKind,
        array $research,
        string $renderedMarkdown
    ): array {
        if (! in_array($reportKind, ['WORKSPACE_VS4', 'CASE_VS5'], true)
            || ($research['provenance']['source_type'] ?? null) !== 'GENERATED_DEMO'
            || ($research['admission'] ?? null) !== 'NOT_ADMITTED'
            || ($research['effective_decision'] ?? null) !== 'HOLD'
            || ($research['authority'] ?? null) !== 'NONE'
            || ($research['valuation_authorized'] ?? null) !== false
            || ($research['trading_authorized'] ?? null) !== false
            || ! is_string($research['provenance']['source_id'] ?? null)
            || ($research['provenance']['source_id'] ?? '') === ''
            || app(ByokSnapshotLineage::class)->isRestricted($research)
        ) {
            throw new \RuntimeException('II_REPORT_SYNTHETIC_BOUNDARY_DENIED');
        }

        $registry = app(LocalLineageRegistry::class);
        $sourceSha = hash('sha256', json_encode([
            'source_id' => $research['provenance']['source_id'],
            'ticker' => $research['ticker'] ?? null,
            'history' => $research['history'] ?? [],
            'quality' => $research['quality'] ?? [],
            'provenance' => $research['provenance'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        // Include fixture-content digest in the source reference to preserve
        // immutable history when a generated demo fixture is revised.
        $source = $registry->registerSource(
            $ownerId, 'synthetic_fixture',
            hash('sha256', 'II_SYNTHETIC_FIXTURE_V1|'.$sourceSha),
            $sourceSha, [
                'source_name' => 'II_GENERATED_SYNTHETIC_DEMO',
                'publisher' => 'Investment Intelligence local application',
                'license_status' => 'synthetic_fixture',
                'usage_rights' => 'synthetic_only',
            ]
        );
        if (! $registry->verifyTrace($ownerId, $source['id'])) {
            throw new \RuntimeException('II_REPORT_SOURCE_INTEGRITY_FAILED');
        }

        $outputSha = hash('sha256', $renderedMarkdown);
        $report = $registry->registerTransformation(
            $ownerId, 'synthetic_report',
            hash('sha256', implode('|', [$reportKind, $source['id'], $outputSha])),
            'RENDER_SYNTHETIC_REPORT_V1',
            $outputSha, [$source['id']]
        );
        if (! $registry->verifyTrace($ownerId, $report['id'])
            || $report['rights_basis'] === 'RESTRICTED_BYOK'
            || $report['can_cloud_transfer'] || $report['can_export']) {
            throw new \RuntimeException('II_REPORT_LINEAGE_VERIFICATION_FAILED');
        }

        // A demonstrated synthetic file can be downloaded through its
        // existing explicitly-demo route. can_export=false forbids treating
        // this metadata record as a general vendor-data export license.
        return [
            'node_id' => $report['id'],
            'content_sha256' => $outputSha,
            'status' => 'VERIFIED_SYNTHETIC_RESEARCH_ONLY',
        ];
    }
}
