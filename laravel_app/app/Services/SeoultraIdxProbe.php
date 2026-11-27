<?php

namespace App\Services;

/** Local, pinned-byte diagnostic only. No acquisition, storage or admission. */
final class SeoultraIdxProbe
{
    public const COMMIT = '90dfaaad66410d2b12211361fe67c5f8ed35d7b8';
    public const BLOBS = [
        'BBCA' => 'de44af5e441e9ad88eb593d627a5d9834f445af0',
        'BBRI' => '117a584c240af23366a948d4bb97a18faca57706',
        'BMRI' => '51d9ff9e1d0fecee919c0fb1934037f901754a2b',
    ];
    public const MAX_BYTES = 500000;

    public function read(string $path, string $ticker): array
    {
        if (!isset(self::BLOBS[$ticker]) || !is_file($path)) {
            throw new \InvalidArgumentException('II_SEOULTRA_INPUT_INVALID');
        }
        $raw = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES
            || !hash_equals(self::BLOBS[$ticker], IdxHistoricalSnapshotSource::gitBlobHash($raw))) {
            throw new \InvalidArgumentException('II_SEOULTRA_PIN_MISMATCH');
        }
        return array_replace($this->parse($raw, $ticker), [
            'source_commit' => self::COMMIT, 'source_blob' => self::BLOBS[$ticker],
            'source_url' => 'https://github.com/seoultra/stocks-idx-data/blob/'.self::COMMIT.'/prices/'.$ticker.'.json',
            'sha256' => hash('sha256', $raw), 'pin_verified' => true,
        ]);
    }

    /** Pure schema diagnostic; callers must not treat unpinned results as authenticated. */
    public function parse(string $raw, string $ticker): array
    {
        if (strlen($raw) > self::MAX_BYTES || !preg_match('/^[A-Z0-9]{4}$/D', $ticker)) {
            throw new \InvalidArgumentException('II_SEOULTRA_INPUT_INVALID');
        }
        $j = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($j) || ($j['schema'] ?? null) !== 1 || ($j['code'] ?? null) !== $ticker
            || ($j['exchange'] ?? null) !== 'IDX' || ($j['currency'] ?? null) !== 'IDR'
            || ($j['adjusted'] ?? null) !== false
            || ($j['source']['name'] ?? null) !== 'Yahoo Finance'
            || ($j['source']['symbol'] ?? null) !== $ticker.'.JK'
            || ($j['columns'] ?? null) !== ['date','open','high','low','close','volume']
            || !is_array($j['rows'] ?? null) || !array_is_list($j['rows'])
            || count($j['rows']) !== ($j['sessions'] ?? null)
            || count($j['rows']) < 60 || count($j['rows']) > 2000) {
            throw new \InvalidArgumentException('II_SEOULTRA_SCHEMA_INVALID');
        }
        $rows = [];
        foreach ($j['rows'] as $r) {
            if (!is_array($r) || !array_is_list($r) || count($r) !== 6 || !is_string($r[0])
                || $r[0] >= now('Asia/Jakarta')->toDateString()
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $r[0])
                || (int) (new \DateTimeImmutable($r[0]))->format('N') > 5) {
                throw new \InvalidArgumentException('II_SEOULTRA_ROW_INVALID');
            }
            foreach (array_slice($r, 1) as $v) {
                if (!is_int($v) && !is_float($v)) {
                    throw new \InvalidArgumentException('II_SEOULTRA_ROW_INVALID');
                }
            }
            $rows[] = array_combine($j['columns'], $r);
        }
        if (!app(ResearchSeriesIntegrity::class)->valid(['rows'=>$rows])
            || $rows[0]['date'] !== ($j['first_date'] ?? null)
            || $rows[array_key_last($rows)]['date'] !== ($j['last_date'] ?? null)) {
            throw new \InvalidArgumentException('II_SEOULTRA_SERIES_INVALID');
        }
        $actions = [];
        foreach (['splits' => $j['splits'] ?? [], 'reversed' => $j['source']['reversed'] ?? []] as $kind => $items) {
            if (!is_array($items) || !array_is_list($items) || count($items) > 100) {
                throw new \InvalidArgumentException('II_SEOULTRA_ACTION_INVALID');
            }
            foreach ($items as $item) {
                $date = $item['ex_date'] ?? '';
                $factor = $item[$kind === 'splits' ? 'factor' : 'yahoo_factor'] ?? null;
                $dt = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
                if (!$dt || $dt->format('Y-m-d') !== $date || !is_numeric($factor)
                    || !is_finite((float)$factor) || (float)$factor <= 0) {
                    throw new \InvalidArgumentException('II_SEOULTRA_ACTION_INVALID');
                }
                $actions[] = ['date'=>$date,'factor'=>(float)$factor,'type'=>$kind,'verification'=>'PUBLISHER_DECLARED_ONLY'];
            }
        }
        return ['ticker'=>$ticker,'rows'=>$rows,'sessions'=>count($rows),'last_date'=>$j['last_date'],
            'market'=>'REGULAR_DECLARED','currency'=>'IDR','basis'=>'AS_TRADED_DECLARED',
            'upstream'=>'YAHOO_FINANCE','rights'=>'UPSTREAM_RIGHTS_UNVERIFIED',
            'actions'=>$actions,'pin_verified'=>false,'admitted'=>false,'trading_authorized'=>false];
    }
}
