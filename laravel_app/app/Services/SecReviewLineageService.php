<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Binds a single on-demand SEC normalization REVIEW CSV to source evidence.
 * This is a local, user-initiated review artifact, not a commercial
 * redistribution license or an admitted financial statement.
 *
 * Only source/output digests are recorded; never raw SEC JSON/CSV or contact.
 */
final class SecReviewLineageService
{
    public function attest(int $ownerId, array $result, string $csv): array
    {
        $cik = (string) ($result['cik'] ?? '');
        $sha = (string) ($result['source_sha256'] ?? '');
        $origin = (string) ($result['fetch_origin'] ?? 'USER_PROVIDED_JSON');
        if ($ownerId < 1
            || ! preg_match('/^[0-9]{10}$/D', $cik)
            || ! preg_match('/^[a-f0-9]{64}$/D', $sha)
            || ! in_array($origin, ['SEC_OFFICIAL_API', 'USER_PROVIDED_JSON'], true)
            || ($result['source_url'] ?? null) !== 'https://data.sec.gov/api/xbrl/companyfacts/CIK'.$cik.'.json'
            || ($result['requires_user_reconciliation'] ?? null) !== true
            || ($result['external_fetch_performed'] ?? false) !== false
            || count($result['periods'] ?? []) < 1
            || count($result['periods'] ?? []) > 5
        ) {
            throw new \RuntimeException('II_SEC_REVIEW_SOURCE_BOUNDARY_DENIED');
        }
        return DB::transaction(function () use ($ownerId, $result, $csv, $cik, $sha, $origin): array {
        $registry = app(LocalLineageRegistry::class);
        $source = $registry->registerSource(
            $ownerId,
            $origin === 'SEC_OFFICIAL_API' ? 'sec_official_response' : 'sec_user_uploaded_json',
            hash('sha256', implode('|', [$origin, $cik, $sha])),
            $sha,
            [
                'source_name' => $origin === 'SEC_OFFICIAL_API'
                    ? 'SEC_OFFICIAL_COMPANYFACTS_ON_DEMAND' : 'SEC_USER_SUPPLIED_JSON_UNVERIFIED',
                'publisher' => 'U.S. Securities and Exchange Commission',
                'source_url' => 'https://data.sec.gov/api/xbrl/companyfacts/CIK'.$cik.'.json',
                'license_status' => 'local_review_only',
                'usage_rights' => 'not_cleared_for_redistribution',
            ]
        );
        if ($source['rights_basis'] === 'RESTRICTED_BYOK'
            || ! $registry->verifyTrace($ownerId, $source['id'])) {
            throw new \RuntimeException('II_SEC_REVIEW_SOURCE_INTEGRITY_FAILED');
        }

        $csvSha = hash('sha256', $csv);
        $node = $registry->registerTransformation(
            $ownerId, 'sec_review_csv',
            hash('sha256', implode('|', ['SEC_REVIEW_V1', $source['id'], $csvSha])),
            'NORMALIZE_SEC_REVIEW_CSV_V1', $csvSha, [$source['id']]
        );
        if ($node['rights_basis'] === 'RESTRICTED_BYOK'
            || ! $registry->verifyTrace($ownerId, $node['id'])) {
            throw new \RuntimeException('II_SEC_REVIEW_TRANSFORM_INTEGRITY_FAILED');
        }

        return [
            'node_id' => $node['id'], 'content_sha256' => $csvSha,
            'status' => $origin === 'SEC_OFFICIAL_API'
                ? 'SOURCE_OFFICIAL_REVIEW_REQUIRED' : 'USER_UPLOAD_REVIEW_REQUIRED',
        ];
        }, 1);
    }
}
