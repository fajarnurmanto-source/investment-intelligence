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
        foreach (['source_name', 'publisher'] as $field) {
            $name = strtolower(trim((string) ($source[$field] ?? '')));
            if (preg_match('/\bmarketdata\s*(?:\.\s*app|byok)\b/i', $name)) {
                throw new \InvalidArgumentException('II_BYOK_RESTRICTED_SOURCE_CANNOT_BE_IMPORTED');
            }
        }

        foreach (['source_url', 'terms_url'] as $field) {
            $url = trim((string) ($source[$field] ?? ''));
            if ($url === '') {
                continue;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === 'marketdata.app' || str_ends_with($host, '.marketdata.app')) {
                throw new \InvalidArgumentException('II_BYOK_RESTRICTED_SOURCE_CANNOT_BE_IMPORTED');
            }
        }
    }
}
