<?php

namespace App\Services;

/**
 * Prevent a restricted BYOK vendor feed from being relabeled as user-provided
 * market prices or financial evidence and escaping through legacy CSV exports.
 * This protects known source declarations; dishonest/missing provenance still
 * requires explicit downstream auditing and cannot be inferred from numbers.
 */
final class ByokIntakeFirewall
{
    public function assertIndependentSource(array $source): void
    {
        // Share the same provenance classifier as derived snapshots so a
        // new provider restriction cannot drift between read and write gates.
        if (app(ByokSnapshotLineage::class)->isRestricted($source)) {
            throw new \InvalidArgumentException('II_BYOK_RESTRICTED_SOURCE_CANNOT_BE_IMPORTED');
        }
    }

}
