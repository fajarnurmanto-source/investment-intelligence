<?php

namespace App\Services;

/**
 * Rights gate for external market feeds. Unknown rights are always denied.
 * Application-created and independently sourced datasets retain their own rights.
 */
final class ByokRightsPolicy
{
    public const RESTRICTED = 'MARKETDATA_BYOK_IN_APP_ONLY';

    public function capabilities(string $provider): array
    {
        return match ($provider) {
            'marketdata' => [
                'display_in_app' => true,
                'local_calculation' => true,
                'export' => false,
                'clipboard' => false,
                'external_api' => false,
                'cloud_llm' => false,
                'sync' => false,
                'share' => false,
                'raw_archive' => false,
                'background_fetch' => false,
                'storage' => false, // phase 1 is memory-only
            ],
            default => [
                'display_in_app' => false,
                'local_calculation' => false,
                'export' => false,
                'clipboard' => false,
                'external_api' => false,
                'cloud_llm' => false,
                'sync' => false,
                'share' => false,
                'raw_archive' => false,
                'background_fetch' => false,
                'storage' => false,
            ],
        };
    }

    public function allows(string $provider, string $capability): bool
    {
        return $this->capabilities($provider)[$capability] ?? false;
    }
}
