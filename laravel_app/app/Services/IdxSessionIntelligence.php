<?php

namespace App\Services;

/** Descriptive single-session statistics; no admission, forecasts or trade signals. */
final class IdxSessionIntelligence
{
    public function analyze(string $raw): array
    {
        [$header, $rows] = app(IdxXlsxExportReader::class)->read($raw, 'Sheet1', 1);
        if ($header !== IdxDailyEvidenceWarehouse::HEADERS) {
            throw new \InvalidArgumentException('II_IDX_INTELLIGENCE_SCHEMA_INVALID');
        }
        return $this->summarize($rows) + ['sha256' => hash('sha256', $raw)];
    }

    public function summarize(array $rows): array
    {
        if (!$rows || count($rows) > 2000) {
            throw new \InvalidArgumentException('II_IDX_INTELLIGENCE_ROWS_INVALID');
        }
        $seen = [];
        $date = null;
        $counts = ['up'=>0,'down'=>0,'flat'=>0,'excluded'=>0,'zero_open'=>0,'zero_volume'=>0,'missing_volume'=>0,'invalid_ohlc'=>0,'positive_volume'=>0];
        $rank = [];
        $flow = [];
        $value = [];
        foreach ($rows as $row) {
            $ticker = $row['Stock Code'] ?? '';
            $session = $row['Last Trading Date'] ?? '';
            $parsed = \DateTimeImmutable::createFromFormat('!d M Y', $session);
            if (!preg_match('/^[A-Z0-9]{4,5}$/D', $ticker) || isset($seen[$ticker])
                || !$parsed || $parsed->format('d M Y') !== $session || ($date !== null && $date !== $session)) {
                throw new \InvalidArgumentException('II_IDX_INTELLIGENCE_IDENTITY_INVALID');
            }
            $seen[$ticker] = true;
            $date = $session;
            $v = $this->number($row, 'Volume', true);
            $o = $this->number($row, 'Open Price');
            $h = $this->number($row, 'High');
            $l = $this->number($row, 'Low');
            $c = $this->number($row, 'Close');
            $p = $this->number($row, 'Previous');
            $counts['zero_open'] += (int) ($o === 0.0);
            $counts['zero_volume'] += (int) ($v === 0.0);
            $counts['missing_volume'] += (int) ($v === null);
            $counts['positive_volume'] += (int) ($v !== null && $v > 0);
            $valid = $o !== null && $h !== null && $l !== null && $c !== null
                && $o > 0 && $l > 0 && $c > 0 && $h >= max($o,$c) && $l <= min($o,$c);
            $counts['invalid_ohlc'] += (int) (!$valid);
            if (!$valid || $v === null || $v <= 0 || $p === null || $p <= 0) {
                $counts['excluded']++;
            } else {
                $counts[$c > $p ? 'up' : ($c < $p ? 'down' : 'flat')]++;
                $rank[] = ['ticker'=>$ticker,'close_vs_previous_pct'=>100*($c-$p)/$p,
                    'open_to_close_pct'=>100*($c-$o)/$o,'range_vs_open_pct'=>100*($h-$l)/$o];
            }
            $buy = $this->number($row, 'Foreign Buy', true);
            $sell = $this->number($row, 'Foreign Sell', true);
            if ($buy !== null && $sell !== null) {
                $flow[] = ['ticker'=>$ticker,'raw_net'=>$buy-$sell];
            }
            $amount = $this->number($row, 'Value', true);
            $frequency = $this->number($row, 'Frequency', true);
            if ($amount !== null && $v !== null && $frequency !== null) {
                $value[] = ['ticker'=>$ticker,'raw_value'=>$amount,'raw_volume'=>$v,'raw_frequency'=>$frequency];
            }
        }
        $sort = static function (array $items, string $key): array {
            usort($items, fn ($a,$b) => ($b[$key] <=> $a[$key]) ?: strcmp($a['ticker'],$b['ticker']));
            return $items;
        };
        $rank = $sort($rank, 'close_vs_previous_pct');
        $flow = $sort($flow, 'raw_net');
        $value = $sort($value, 'raw_value');
        return [
            'session'=>$date,'universe'=>count($seen),'breadth'=>$counts,
            'breadth_basis'=>'positive volume, coherent positive OHLC and positive Previous',
            'top_price_moves'=>array_slice($rank,0,10),
            'bottom_price_moves'=>array_slice(array_reverse($rank),0,10),
            'foreign_field_coverage'=>count($flow),
            'foreign_raw_net_sum'=>array_sum(array_column($flow,'raw_net')),
            'top_foreign_raw_net'=>array_slice($flow,0,10),
            'bottom_foreign_raw_net'=>array_slice(array_reverse($flow),0,10),
            'liquidity_field_coverage'=>count($value),
            'top_raw_value'=>array_slice($value,0,10),
            'semantics'=>'RAW_SOURCE_FIELDS_UNVERIFIED_UNITS_AND_SCOPE',
            'admitted'=>false,'trading_authorized'=>false,
        ];
    }

    private function number(array $row, string $key, bool $integer = false): ?float
    {
        $v = $row[$key] ?? null;
        if (!is_string($v) || !preg_match($integer ? '/^\d{1,15}$/D' : '/^\d{1,15}(?:\.\d{1,6})?$/D', $v)) {
            return null;
        }
        return (float) $v;
    }
}
