<?php

namespace App\Services;

/** Immutable public dataset manifest. Candidates are not accepted observations. */
final class IdxHistoricalCatalog
{
    public function entries(): array
    {
        $manifest = json_decode(file_get_contents(resource_path('data/idx-historical-catalog.json')), true, 8, JSON_THROW_ON_ERROR);
        if (($manifest['commit'] ?? null) !== IdxHistoricalSnapshotSource::COMMIT || !is_array($manifest['entries'] ?? null)) {
            throw new \RuntimeException('II_IDX_CATALOG_INVALID');
        }
        return $manifest['entries'];
    }

    public function candidate(string $ticker): array
    {
        if (!preg_match('/^[A-Z0-9]{4}$/D', $ticker)) {
            throw new \InvalidArgumentException('II_IDX_SNAPSHOT_TICKER_UNSUPPORTED');
        }
        $entry = $this->entries()[$ticker] ?? null;
        if (!is_array($entry) || !preg_match('/^[a-f0-9]{40}$/D', $entry['blob'] ?? '')
            || !is_int($entry['bytes'] ?? null) || $entry['bytes'] < 1 || $entry['bytes'] > IdxHistoricalSnapshotSource::MAX_BYTES) {
            throw new \InvalidArgumentException('II_IDX_SNAPSHOT_TICKER_UNSUPPORTED');
        }
        return $entry + ['path' => 'Saham/Semua/'.$ticker.'.csv'];
    }

    public function resolve(string $ticker): array
    {
        if (isset(IdxHistoricalSnapshotSource::BLOBS[$ticker])) {
            return ['blob' => IdxHistoricalSnapshotSource::BLOBS[$ticker], 'path' => 'Saham/LQ45/'.$ticker.'.csv'];
        }
        return $this->candidate($ticker);
    }
}
