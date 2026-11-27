<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/** Explicit one-shot direct acquisition; never activates discovery or scheduler. */
final class DirectMarketAcquisition
{
    public function run(int $owner, string $symbol, string $mic, string $currency, string $timezone, bool $confirmed): array
    {
        if (!$confirmed) {
            throw new \InvalidArgumentException('II_DIRECT_CONFIRMATION_REQUIRED');
        }
        if (!preg_match('/^[A-Z0-9.\-]{1,16}$/D', $symbol) || str_starts_with($symbol, 'DEMO')
            || !preg_match('/^[A-Z0-9]{4}$/D', $mic) || !preg_match('/^[A-Z]{3}$/D', $currency)
            || !in_array($timezone, MarketSourceMetadataService::SUPPORTED_TIMEZONES, true)) {
            throw new \InvalidArgumentException('II_DIRECT_IDENTITY_INVALID');
        }
        return Cache::lock('ii-market-universe-run', 3600)->block(1, function () use ($owner, $symbol, $mic, $currency, $timezone): array {
            $settings = app(MarketFeedSettings::class);
            $cfg = $settings->current();
            if ($owner < 1 || (int) ($cfg['owner_id'] ?? 0) !== $owner || !$settings->canManage($owner)) {
                throw new \InvalidArgumentException('II_DIRECT_OWNER_REQUIRED');
            }
            if (($cfg['enabled'] ?? false) || ($cfg['discovery_enabled'] ?? false)) {
                throw new \InvalidArgumentException('II_DIRECT_STOP_AUTOMATION_FIRST');
            }
            if (($cfg['provider'] ?? null) !== 'twelve_data' || trim((string) ($cfg['rights_reference'] ?? '')) === ''
                || ($cfg['storage_permitted'] ?? false) !== true || ($cfg['automation_permitted'] ?? false) !== true) {
                throw new \InvalidArgumentException('II_DIRECT_RIGHTS_REQUIRED');
            }
            if (!in_array($mic, $cfg['mics'] ?? [], true)) {
                throw new \InvalidArgumentException('II_DIRECT_MIC_OUT_OF_SCOPE');
            }
            if (($cfg['credential_replacement_pending'] ?? true) || !app(LocalByokCredentialVault::class)->connected($owner, 'twelve_data')) {
                throw new \InvalidArgumentException('II_DIRECT_CREDENTIAL_REQUIRED');
            }
            $operation = array_replace($cfg, [
                'direct_once' => true, 'enabled' => true, 'ticker' => $symbol, 'provider_symbol' => $symbol, 'provider_mic' => $mic,
                'currency' => $currency, 'session_timezone' => $timezone, 'price_adjustment' => 'unadjusted',
                'source_name' => 'Twelve Data', 'source_url' => TwelveDataDailyAdapter::URL,
            ]);
            $status = app(MarketAcquisitionLedger::class)->run($operation,
                fn () => app(LicensedMarketFeedService::class)->sync($operation));
            $doc = app(SignalInsightService::class)->latestDocument($owner, $symbol);
            if (!in_array($status, ['IMPORTED', 'UNCHANGED'], true)
                || ($doc['lineage_source_status'] ?? null) !== 'REGISTERED'
                || ($doc['provider_identity'] ?? null) !== $symbol.'@'.$mic) {
                throw new \RuntimeException('II_DIRECT_READBACK_FAILED');
            }
            return ['status' => $status, 'identity' => $symbol.'@'.$mic,
                'response_sha256' => $doc['provider_response_sha256'] ?? null,
                'insight' => app(SignalInsightService::class)->insight($doc)];
        });
    }
}
