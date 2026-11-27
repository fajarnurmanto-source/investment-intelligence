<?php

namespace App\Services;

/** Structural integrity is not independent price verification. */
final class ResearchSeriesIntegrity
{
    public function valid(array $document): bool
    {
        if (!is_array($document['rows'] ?? null) || !array_is_list($document['rows'])
            || count($document['rows']) < 60 || count($document['rows']) > 2000) {
            return false;
        }
        $previous = '';
        $today = now('Asia/Jakarta')->toDateString();
        foreach ($document['rows'] as $row) {
            if (!is_array($row) || !is_string($row['date'] ?? null)) {
                return false;
            }
            $date = $row['date'];
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date || $date <= $previous || $date > $today) {
                return false;
            }
            $previous = $date;
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                if (!is_numeric($row[$field] ?? null) || !is_finite((float) $row[$field])) {
                    return false;
                }
            }
            [$open, $high, $low, $close, $volume] = array_map(
                fn ($field) => (float) $row[$field], ['open', 'high', 'low', 'close', 'volume']
            );
            if (min($open, $high, $low, $close) <= 0 || $low > min($open, $close)
                || $high < max($open, $close) || $volume < 0 || floor($volume) !== $volume) {
                return false;
            }
        }
        return true;
    }
}
