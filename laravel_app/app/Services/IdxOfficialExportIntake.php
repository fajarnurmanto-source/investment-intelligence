<?php

namespace App\Services;

use League\Csv\Reader;

/** Local official export intake. No network, no persistence, no license grant.
 * Receipt binds reviewed export bytes and explicit column semantics. */
final class IdxOfficialExportIntake
{
    public const TERMS = 'https://www.idx.id/id/syarat-penggunaan';
    public const SOURCE = 'https://www.idx.co.id/id/data-pasar/ringkasan-perdagangan/ringkasan-saham';
    public const MAX_BYTES = 20_000_000;

    public function inspect(string $file, string $reviewFile, string $ticker): array
    {
        if (!is_file($file) || !is_file($reviewFile) || !preg_match('/^[A-Z0-9]{4}$/D', $ticker)) {
            throw new \InvalidArgumentException('II_IDX_EXPORT_INPUT_REQUIRED');
        }
        $raw = file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
        $reviewRaw = file_get_contents($reviewFile, false, null, 0, 100001);
        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_BYTES
            || !is_string($reviewRaw) || strlen($reviewRaw) > 100000) {
            throw new \InvalidArgumentException('II_IDX_EXPORT_SIZE_INVALID');
        }
        $r = json_decode($reviewRaw, true, 12, JSON_THROW_ON_ERROR);
        $today = now('Asia/Jakarta')->toDateString();
        $date = $r['session_date'] ?? null;
        $access = $r['access_date'] ?? null;
        $sha = hash('sha256', $raw);
        if (($r['source_url'] ?? null) !== self::SOURCE || ($r['terms_url'] ?? null) !== self::TERMS
            || ($r['document_sha256'] ?? null) !== $sha
            || ($r['acquisition'] ?? null) !== 'MANUAL_OFFICIAL_DOWNLOAD'
            || ($r['usage'] ?? null) !== 'PERSONAL_NONCOMMERCIAL_RESEARCH'
            || ($r['rights_confirmed'] ?? null) !== true || ($r['no_redistribution'] ?? null) !== true
            || ($r['direct_cost_idr'] ?? null) !== 0
            || !is_string($r['reviewer'] ?? null) || trim($r['reviewer']) === '' || strlen($r['reviewer']) > 150
            || !$this->date($date) || !$this->date($access) || $date >= $today || $access < $date || $access > $today
            || ($r['currency'] ?? null) !== 'IDR' || ($r['basis'] ?? null) !== 'AS_TRADED'
            || ($r['price_market'] ?? null) !== 'REGULAR'
            || ($r['format'] ?? null) !== 'ORIGINAL_CSV'
            || ($r['numeric_format'] ?? null) !== 'PLAIN_DECIMAL') {
            throw new \InvalidArgumentException('II_IDX_EXPORT_REVIEW_INVALID');
        }
        $map = $r['columns'] ?? null;
        if (!is_array($map) || !isset($map['ticker']) || count($map) < 2
            || array_diff(array_keys($map), ['ticker','open','high','low','close','volume','nonregular_volume'])
            || count(array_unique(array_values($map))) !== count($map)) {
            throw new \InvalidArgumentException('II_IDX_EXPORT_MAPPING_INVALID');
        }
        foreach ($map as $name) {
            if (!is_string($name) || trim($name) === '' || strlen($name) > 150) {
                throw new \InvalidArgumentException('II_IDX_EXPORT_MAPPING_INVALID');
            }
        }
        $volumeScope = $r['volume_scope'] ?? null;
        $volumeUnit = $r['volume_unit'] ?? null;
        if (isset($map['volume']) && (!in_array($volumeScope, ['REGULAR','TOTAL_MINUS_NONREGULAR'], true)
            || !in_array($volumeUnit, ['SHARES','LOTS'], true)
            || ($volumeScope === 'TOTAL_MINUS_NONREGULAR' && !isset($map['nonregular_volume'])))) {
            throw new \InvalidArgumentException('II_IDX_EXPORT_VOLUME_SCOPE_INVALID');
        }
        if (isset($map['nonregular_volume']) && (!isset($map['volume']) || $volumeScope !== 'TOTAL_MINUS_NONREGULAR')) {
            throw new \InvalidArgumentException('II_IDX_EXPORT_VOLUME_SCOPE_INVALID');
        }
        $csv = Reader::createFromString($raw);
        $csv->setHeaderOffset(0);
        $header = $csv->getHeader();
        if (count($header) !== count(array_unique($header)) || array_diff(array_values($map), $header)) {
            throw new \InvalidArgumentException('II_IDX_EXPORT_HEADER_INVALID');
        }
        $seen = [];
        $result = null;
        $rowCount = 0;
        foreach ($csv->getRecords() as $offset => $row) {
            if (++$rowCount > 2000) {
                throw new \InvalidArgumentException('II_IDX_EXPORT_ROW_LIMIT');
            }
            $code = trim((string)($row[$map['ticker']] ?? ''));
            if (!preg_match('/^[A-Z0-9]{4}$/D', $code) || isset($seen[$code])) {
                throw new \InvalidArgumentException('II_IDX_EXPORT_TICKER_INVALID');
            }
            $seen[$code] = true;
            if ($code !== $ticker) {
                continue;
            }
            $values = [];
            foreach ($map as $field => $column) {
                if ($field === 'ticker') {
                    continue;
                }
                $value = trim((string)($row[$column] ?? ''));
                if (!preg_match('/^\d{1,15}(?:\.\d{1,6})?$/D', $value)) {
                    throw new \InvalidArgumentException('II_IDX_EXPORT_NUMBER_INVALID');
                }
                $n = (float)$value;
                if (in_array($field, ['volume','nonregular_volume'], true)) {
                    if (floor($n) !== $n || $n > 90_000_000_000_000) {
                        throw new \InvalidArgumentException('II_IDX_EXPORT_NUMBER_INVALID');
                    }
                    $n = (int)$n * ($volumeUnit === 'LOTS' ? 100 : 1);
                } elseif ($n <= 0) {
                    throw new \InvalidArgumentException('II_IDX_EXPORT_NUMBER_INVALID');
                }
                $values[$field] = $n;
            }
            if ($volumeScope === 'TOTAL_MINUS_NONREGULAR' && isset($values['volume'])) {
                if ($values['nonregular_volume'] > $values['volume']) {
                    throw new \InvalidArgumentException('II_IDX_EXPORT_VOLUME_CONFLICT');
                }
                $values['volume'] -= $values['nonregular_volume'];
                unset($values['nonregular_volume']);
            }
            if (isset($values['low'], $values['high']) && $values['low'] > $values['high']) {
                throw new \InvalidArgumentException('II_IDX_EXPORT_OHLC_INVALID');
            }
            foreach (['open','close'] as $field) {
                if (isset($values[$field]) && ((isset($values['low']) && $values[$field] < $values['low'])
                    || (isset($values['high']) && $values[$field] > $values['high']))) {
                    throw new \InvalidArgumentException('II_IDX_EXPORT_OHLC_INVALID');
                }
            }
            $result = ['date'=>$date,'locator'=>'CSV record offset '.$offset,'values'=>$values];
        }
        if ($result === null) {
            throw new \InvalidArgumentException('II_IDX_EXPORT_TICKER_MISSING');
        }
        return ['source_url'=>self::SOURCE,'terms_url'=>self::TERMS,'document_sha256'=>$sha,
            'review_sha256'=>hash('sha256',$reviewRaw),'rights_confirmed'=>true,'ticker'=>$ticker,
            'currency'=>'IDR','market'=>'REGULAR','basis'=>'AS_TRADED','access_date'=>$access,
            'rights_status'=>'USER_ATTESTED_NONCOMMERCIAL_ONLY','evidence_authenticity'=>'USER_ATTESTED_EXPORT',
            'observations'=>[$result],'admitted'=>false,'trading_authorized'=>false];
    }

    private function date(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }
}
