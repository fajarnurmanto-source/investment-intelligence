<?php

namespace App\Services;

final class IdxMultisessionIntelligence
{
    public function analyze(array $workbooks): array
    {
        if (count($workbooks) < 2 || count($workbooks) > 5) {
            throw new \InvalidArgumentException('Choose two to five sessions.');
        }
        $sessions = [];
        $bytes = 0;
        foreach ($workbooks as $raw) {
            if (!is_string($raw) || $raw === '' || strlen($raw) > IdxOfficialExportIntake::MAX_BYTES
                || ($bytes += strlen($raw)) > IdxOfficialExportIntake::MAX_BYTES) {
                throw new \InvalidArgumentException('Workbook size exceeds limit.');
            }
            [$header, $rows] = app(IdxXlsxExportReader::class)->read($raw, 'Sheet1', 1);
            $rows = app(IdxSessionIntelligence::class)->normalizeExport($header, $rows);
            $sessions[] = ['rows' => $rows, 'sha256' => hash('sha256', $raw)];
        }
        return $this->compare($sessions);
    }

    public function compare(array $sessions): array
    {
        if (count($sessions) < 2 || count($sessions) > 5) {
            throw new \InvalidArgumentException('Choose two to five sessions.');
        }
        $byDate = [];
        $engine = app(IdxSessionIntelligence::class);
        foreach ($sessions as $session) {
            $summary = $engine->summarize($session['rows']);
            $date = \DateTimeImmutable::createFromFormat('!d M Y', $summary['session'])->format('Y-m-d');
            if (isset($byDate[$date]) || $date >= now('Asia/Jakarta')->toDateString()) {
                throw new \InvalidArgumentException('Duplicate or unfinished session.');
            }
            $rows = [];
            foreach ($session['rows'] as $row) {
                // Reuse the single-session eligibility rule, including positive previous price.
                $one = $engine->summarize([$row]);
                if ($one['breadth']['excluded'] === 0) {
                    $rows[$row['Stock Code']] = ['close' => (float) $row['Close'], 'volume' => (float) $row['Volume']];
                }
            }
            $b = $summary['breadth'];
            $eligible = $b['up'] + $b['down'] + $b['flat'];
            $byDate[$date] = ['date' => $date, 'sha256' => $session['sha256'] ?? null,
                'universe' => $summary['universe'], 'eligible' => $eligible,
                'up' => $b['up'], 'down' => $b['down'], 'flat' => $b['flat'],
                'up_pct' => $eligible ? 100 * $b['up'] / $eligible : null, 'rows' => $rows];
        }
        ksort($byDate);
        $ordered = array_values($byDate);
        $first = $ordered[0];
        $last = $ordered[count($ordered) - 1];
        $matched = $first['rows'];
        foreach ($ordered as $session) {
            $matched = array_intersect_key($matched, $session['rows']);
        }
        $moves = [];
        foreach ($matched as $ticker => $start) {
            $volumes = [];
            foreach (array_slice($ordered, 0, -1) as $session) {
                $volumes[] = $session['rows'][$ticker]['volume'];
            }
            $mean = array_sum($volumes) / count($volumes);
            $moves[] = ['ticker' => $ticker,
                'price_change_pct' => 100 * ($last['rows'][$ticker]['close'] / $start['close'] - 1),
                'raw_volume_ratio' => $last['rows'][$ticker]['volume'] / $mean];
        }
        usort($moves, fn ($a, $b) => ($b['price_change_pct'] <=> $a['price_change_pct']) ?: strcmp($a['ticker'], $b['ticker']));
        $median = null;
        if ($moves) {
            $mid = intdiv(count($moves), 2);
            $median = count($moves) % 2 ? $moves[$mid]['price_change_pct']
                : ($moves[$mid-1]['price_change_pct'] + $moves[$mid]['price_change_pct']) / 2;
        }
        foreach ($moves as &$move) {
            $move['relative_to_matched_median_pp'] = $move['price_change_pct'] - $median;
        }
        unset($move);
        $activity = $moves;
        usort($activity, fn ($a, $b) => ($b['raw_volume_ratio'] <=> $a['raw_volume_ratio']) ?: strcmp($a['ticker'], $b['ticker']));
        return ['sessions' => array_map(function ($s) { unset($s['rows']); return $s; }, $ordered),
            'matched_count' => count($matched), 'median_change_pct' => $median,
            'leaders' => array_slice($moves, 0, 10), 'laggards' => array_slice(array_reverse($moves), 0, 10),
            'activity' => array_slice($activity, 0, 10),
            'admitted' => false, 'trading_authorized' => false];
    }
}
