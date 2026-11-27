<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/** Research-only, bounded source diagnostic. Never promotes or stores prices. */
final class AetherealIdxProbe
{
    public const URL = 'https://dataset.aethereal.top/files/idx-stock-prices.csv';
    public const MAX_BYTES = 40_000_000;
    public const MAX_ROWS = 650_000;

    public function download(bool $rightsConfirmed): array
    {
        if (!$rightsConfirmed) {
            throw new \InvalidArgumentException('II_AETHEREAL_RESEARCH_RIGHTS_REQUIRED');
        }
        // The publisher's declared research license is not upstream clearance.
        // Network access stays denied until explicit upstream review is integrated.
        app(ZeroCostAcquisitionPolicy::class)->assertAllowed(new \GuzzleHttp\Psr7\Request('GET', self::URL));
        $response = Http::connectTimeout(5)->timeout(90)
            ->withOptions(['allow_redirects' => false, 'stream' => true, 'decode_content' => false])
            ->withHeaders(['Accept' => 'text/csv', 'Accept-Encoding' => 'identity'])->get(self::URL);
        $body = $response->toPsrResponse()->getBody();
        try {
            if (!$response->successful()) {
                throw new \RuntimeException('II_AETHEREAL_HTTP_'.$response->status());
            }
            return $this->inspect(fn () => $body->read(65536));
        } finally {
            $body->close();
        }
    }

    /** Bounded transient local file inspection, with no persistence or network. */
    public function inspectFile(string $path): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('II_AETHEREAL_FILE_INVALID');
        }
        $file = fopen($path, 'rb');
        if (!$file) {
            throw new \RuntimeException('II_AETHEREAL_FILE_UNREADABLE');
        }
        try {
            return $this->inspect(fn () => fread($file, 65536));
        } finally {
            fclose($file);
        }
    }

    private function inspect(callable $read): array
    {
        $file = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (!$file) {
            throw new \RuntimeException('II_AETHEREAL_TEMP_FAILED');
        }
        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            while (true) {
                $chunk = $read();
                if (!is_string($chunk)) {
                    throw new \RuntimeException('II_AETHEREAL_READ_FAILED');
                }
                if ($chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_BYTES || fwrite($file, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('II_AETHEREAL_SIZE_LIMIT');
                }
                hash_update($hash, $chunk);
            }
            rewind($file);
            return $this->parse($file) + ['bytes' => $bytes, 'sha256' => hash_final($hash)];
        } finally {
            fclose($file);
        }
    }

    private function parse($file): array
    {
        $header = fgetcsv($file, 4096, ',', '"', '');
        if ($header !== ['date','ticker','open','high','low','close','adj_close','volume']) {
            throw new \InvalidArgumentException('II_AETHEREAL_SCHEMA_MISMATCH');
        }
        $today = now('Asia/Jakarta')->toDateString();
        $rows = 0;
        $bad = 0;
        $duplicates = 0;
        $zeroVolume = 0;
        $adjustmentDifferences = 0;
        $adjustmentMissing = 0;
        $series = [];
        $previousKey = '';
        while (($line = fgetcsv($file, 4096, ',', '"', '')) !== false) {
            if (++$rows > self::MAX_ROWS) {
                throw new \RuntimeException('II_AETHEREAL_ROW_LIMIT');
            }
            if (count($line) !== 8) {
                throw new \InvalidArgumentException('II_AETHEREAL_ROW_SHAPE_INVALID');
            }
            [$date,$ticker,$open,$high,$low,$close,$adjusted,$volume] = $line;
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date || $date >= $today
                || !preg_match('/^[A-Z0-9]{4}$/D', $ticker)) {
                throw new \InvalidArgumentException('II_AETHEREAL_IDENTITY_OR_DATE_INVALID');
            }
            $key = $date.'|'.$ticker;
            if ($key < $previousKey) {
                throw new \InvalidArgumentException('II_AETHEREAL_ORDER_INVALID');
            }
            if ($key === $previousKey) {
                $duplicates++;
                continue;
            }
            $previousKey = $key;
            if (!isset($series[$ticker]) && count($series) >= 200) {
                throw new \RuntimeException('II_AETHEREAL_TICKER_LIMIT');
            }
            $series[$ticker] ??= ['valid'=>0,'invalid'=>0,'first_date'=>null,'last_date'=>null];
            $numbers = [$open,$high,$low,$close,$volume];
            $valid = true;
            foreach ($numbers as $number) {
                if (!is_numeric($number) || !is_finite((float) $number)) {
                    $valid = false;
                }
            }
            if (!$valid || min((float)$open,(float)$high,(float)$low,(float)$close) <= 0
                || (float)$low > min((float)$open,(float)$close)
                || (float)$high < max((float)$open,(float)$close)
                || (float)$volume < 0 || floor((float)$volume) !== (float)$volume) {
                $bad++;
                $series[$ticker]['invalid']++;
                continue;
            }
            if ((float)$volume === 0.0) {
                $zeroVolume++;
            }
            if (!is_numeric($adjusted) || !is_finite((float)$adjusted) || (float)$adjusted <= 0) {
                $adjustmentMissing++;
            } elseif ((float)$adjusted !== (float)$close) {
                $adjustmentDifferences++;
            }
            $series[$ticker]['valid']++;
            $series[$ticker]['first_date'] ??= $date;
            $series[$ticker]['last_date'] = $date;
        }
        if ($rows === 0) {
            throw new \InvalidArgumentException('II_AETHEREAL_EMPTY');
        }
        foreach ($series as &$item) {
            $item['age_days'] = $item['last_date'] === null ? null : (int) \DateTimeImmutable::createFromFormat('!Y-m-d', $item['last_date'])->diff(\DateTimeImmutable::createFromFormat('!Y-m-d', $today))->days;
        }
        unset($item);
        return ['status'=>'DIAGNOSTIC_ONLY','source_url'=>self::URL,'upstream'=>'YAHOO_FINANCE',
            'rights'=>'UPSTREAM_CLEARANCE_PENDING','price_adjustment'=>'UNKNOWN',
            'survivorship_bias'=>true,'rows'=>$rows,'invalid_rows'=>$bad,'duplicate_rows'=>$duplicates,
            'valid_rows'=>$rows-$bad-$duplicates,'zero_volume_rows'=>$zeroVolume,
            'adjusted_close_missing'=>$adjustmentMissing,'adjusted_close_differs'=>$adjustmentDifferences,
            'instruments'=>count($series),'series'=>$series,'admitted'=>false,'trading_authorized'=>false];
    }
}
