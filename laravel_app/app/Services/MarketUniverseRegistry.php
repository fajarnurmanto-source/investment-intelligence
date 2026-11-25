<?php

namespace App\Services;

use App\Models\User;

/** Operator-managed, private configuration. No credentials in instrument entries. */
final class MarketUniverseRegistry
{
    public function entries(array $base): array
    {
        $raw = $base['universe_json'] ?? null;
        if ($raw === null || $raw === '') {
            return [$base];
        }
        if (!is_string($raw) || strlen($raw) > 32768) {
            throw new \InvalidArgumentException('II_UNIVERSE_INVALID');
        }
        $items = json_decode($raw, true, 16);
        $limit = filter_var($base['request_budget'] ?? 10, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 50]]);
        if (!$limit || !is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > $limit) {
            throw new \InvalidArgumentException('II_UNIVERSE_BUDGET_OR_SCHEMA_INVALID');
        }
        $owner = filter_var($base['owner_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$owner || !User::query()->whereKey($owner)->exists()
            || ($base['storage_permitted'] ?? false) !== true || ($base['automation_permitted'] ?? false) !== true
            || trim((string) ($base['rights_reference'] ?? '')) === '') {
            throw new \InvalidArgumentException('II_UNIVERSE_OWNER_OR_RIGHTS_INVALID');
        }
        $provider = $base['provider'] ?? 'local';
        if (!in_array($provider, ['local', 'twelve_data'], true)) {
            throw new \InvalidArgumentException('II_PROVIDER_UNKNOWN');
        }
        $entries = [];
        $identities = [];
        $allowed = ['ticker', 'provider_symbol', 'provider_mic', 'currency', 'price_adjustment', 'session_timezone'];
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), $allowed) !== []) {
                throw new \InvalidArgumentException('II_UNIVERSE_ENTRY_INVALID');
            }
            foreach ($item as $value) {
                if (!is_string($value) || strlen($value) > 64) {
                    throw new \InvalidArgumentException('II_UNIVERSE_ENTRY_INVALID');
                }
            }
            $entry = array_replace($base, array_intersect_key($item, array_flip($allowed)));
            $ticker = strtoupper(trim($item['ticker'] ?? ''));
            if (!preg_match('/^[A-Z0-9.\-]{1,16}$/D', $ticker) || str_starts_with($ticker, 'DEMO') || isset($entries[$ticker])) {
                throw new \InvalidArgumentException('II_UNIVERSE_TICKER_INVALID_OR_DUPLICATE');
            }
            $entry['ticker'] = $ticker;
            $metadata = MarketSourceMetadataService::forImport($entry);
            if (!$metadata['currency'] || !$metadata['price_adjustment'] || !$metadata['session_timezone']) {
                throw new \InvalidArgumentException('II_UNIVERSE_METADATA_REQUIRED');
            }
            if ($provider === 'twelve_data') {
                $symbol = strtoupper(trim($item['provider_symbol'] ?? ''));
                $mic = strtoupper(trim($item['provider_mic'] ?? ''));
                $identity = $symbol.'@'.$mic;
                if (!preg_match('/^[A-Z0-9.\-]{1,16}$/D', $symbol) || !preg_match('/^[A-Z0-9]{4}$/D', $mic)
                    || isset($identities[$identity]) || $metadata['price_adjustment'] !== 'unadjusted') {
                    throw new \InvalidArgumentException('II_UNIVERSE_PROVIDER_IDENTITY_INVALID');
                }
                $identities[$identity] = true;
                $entry['provider_symbol'] = $symbol;
                $entry['provider_mic'] = $mic;
            }
            unset($entry['universe_json']);
            $entries[$ticker] = $entry;
        }
        return array_values($entries);
    }
}
