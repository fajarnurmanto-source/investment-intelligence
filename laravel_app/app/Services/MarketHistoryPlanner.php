<?php

namespace App\Services;

final class MarketHistoryPlanner
{
    /** A read-only plan; no admission, entitlement or trading decision. */
    public function plan(array $entry, ?array $document): array
    {
        $target = filter_var($entry['history_sessions'] ?? 250, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 60, 'max_range' => 2000]]);
        if (!$target) {
            throw new \InvalidArgumentException('II_PROVIDER_HISTORY_LIMIT_INVALID');
        }
        $meta = MarketSourceMetadataService::forImport($entry);
        $result = ['action' => 'BOOTSTRAP', 'target_sessions' => $target, 'checkpoint' => null,
            'sessions' => 0, 'long_calendar_gaps' => 0];
        if ($document === null) {
            return $result;
        }
        $identity = strtoupper($entry['provider_symbol'] ?? '').'@'.strtoupper($entry['provider_mic'] ?? '');
        if (($document['provider_identity'] ?? null) !== $identity
            || ($document['acquisition_mode'] ?? null) !== 'LICENSED_PROVIDER_API'
            || ($document['source_name'] ?? null) !== ($entry['source_name'] ?? null)
            || ($document['source_url'] ?? null) !== ($entry['source_url'] ?? null)
            || ($document['rights_reference'] ?? null) !== ($entry['rights_reference'] ?? null)) {
            return array_replace($result, ['action' => 'BLOCK_SOURCE_CONFLICT']);
        }
        foreach (['currency', 'price_adjustment', 'session_timezone'] as $field) {
            if (($document[$field] ?? null) !== $meta[$field]) {
                return array_replace($result, ['action' => 'BLOCK_METADATA_CONFLICT']);
            }
        }
        $rows = $document['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 60 || count($rows) > 2000
            || !$meta['session_timezone']) {
            return array_replace($result, ['action' => 'BLOCK_INVALID_HISTORY']);
        }
        $zone = new \DateTimeZone($meta['session_timezone']);
        $today = new \DateTimeImmutable('today', $zone);
        $last = null;
        $gaps = 0;
        foreach ($rows as $row) {
            $raw = $row['date'] ?? null;
            $date = is_string($raw) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $zone) : false;
            if (!$date || $date->format('Y-m-d') !== $raw || $date >= $today || ($last && $date <= $last)) {
                return array_replace($result, ['action' => 'BLOCK_INVALID_HISTORY']);
            }
            if ($last && $last->diff($date)->days > 7) {
                $gaps++;
            }
            $last = $date;
        }
        $result['sessions'] = count($rows);
        $result['long_calendar_gaps'] = $gaps;
        if ($gaps > 0) {
            // Calendar gaps are warnings, not proof of missing exchange sessions.
            $result['action'] = 'FULL_WINDOW_GAP_REVIEW';
        } elseif (count($rows) < $target || ($entry['incremental_enabled'] ?? true) !== true) {
            $result['action'] = 'FULL_WINDOW';
        } else {
            $result['action'] = 'INCREMENTAL';
            $result['checkpoint'] = $last->format('Y-m-d');
        }
        return $result;
    }
}
