<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class IdxHistoricalSnapshotSource implements HistoricalMarketSnapshotSource
{
    public const COMMIT = 'bc0ac7712ce5e46f1067349e13ab9f338883c6c4';
    public const BLOB = '1606574aab3985cc2f1d2cf953ba426909001a6a';
    public const PATH = 'Saham/LQ45/BBCA.csv';
    public const URL = 'https://github.com/wildangunawan/Dataset-Saham-IDX/blob/'.self::COMMIT.'/'.self::PATH;
    public const RAW_URL = 'https://raw.githubusercontent.com/wildangunawan/Dataset-Saham-IDX/'.self::COMMIT.'/'.self::PATH;
    public const TERMS = 'https://www.idx.id/id/syarat-penggunaan';
    public const MAX_BYTES = 1048576;

    public const BLOBS = [
        'BBCA' => '1606574aab3985cc2f1d2cf953ba426909001a6a',
        'BBRI' => '43da07160634bca31bc91be2acd0b4296c3c863b',
        'BMRI' => '4693b9364ab017740c11201f654de165f4333c2d',
        'TLKM' => '97dd3a11a1152469c53c0e45eb6f53b3a2783d46',
        'ASII' => '0468bafe9056112b41451653bab5ba3975aaedaa',
        'ANTM' => 'fddbc94fc2cb892881fa958560e6788621033111',
        'ICBP' => '515a5c3211588b66f5cbb1407df0a1f186cc8625',
        'UNTR' => '293a0585a5d709341f015c5047901b9a3eb70cbb',
        'KLBF' => '2612c5bd5d5354ebca158ef1caaff4cfba977768',
        'INDF' => '33d2cbb7d4398285feab4440105eba52b4e1989e',
        'PGAS' => 'c883deb52edf21ee9e51164cc08920978e4ba231',
        'PTBA' => '62247f31a5a5e91cc61a486b2120c6a1ac8bf6ad',
        'MEDC' => '1a4218597b01565dd1cef86f72aa57166efa3694',
        'SMGR' => '21ef6cd17e307b6809d191f23155d0632f6ca8f8',
        'EXCL' => 'c1560921ca392ad1f99f1903be25010328527ce5',
        'TOWR' => '3f6517e538218643ed0f011310044965926fc69e',
        'BRIS' => '5e1f552dcbb0d7bcc4175c03791ec4d19b71d420',
    ];

    public function __construct(private readonly string $ticker = 'BBCA') {}

    public function snapshot(): array
    {
        $entry = app(IdxHistoricalCatalog::class)->resolve($this->ticker);
        $blob = $entry['blob'];
        $path = $entry['path'];
        $url = 'https://github.com/wildangunawan/Dataset-Saham-IDX/blob/'.self::COMMIT.'/'.$path;
        $rawUrl = 'https://raw.githubusercontent.com/wildangunawan/Dataset-Saham-IDX/'.self::COMMIT.'/'.$path;
        try {
            $response = Http::connectTimeout(5)->timeout(30)->withOptions(['allow_redirects' => false])
                ->get($rawUrl);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new \RuntimeException('II_IDX_SNAPSHOT_NETWORK_ERROR');
        }
        if ($response->status() === 429 || $response->serverError()) {
            throw new \RuntimeException('II_IDX_SNAPSHOT_TEMPORARY_ERROR');
        }
        $raw = $response->body();
        if (!$response->successful() || strlen($raw) > self::MAX_BYTES || $raw === '') {
            throw new \InvalidArgumentException('II_IDX_SNAPSHOT_RESPONSE_REJECTED');
        }
        if ((isset($entry['bytes']) && strlen($raw) !== $entry['bytes']) || !hash_equals($blob, self::gitBlobHash($raw))) {
            throw new \InvalidArgumentException('II_IDX_SNAPSHOT_BLOB_MISMATCH');
        }
        $result = self::normalize($raw);
        if ($result['source_rows'] !== 1355 || $result['rows'][0]['date'] !== '2024-01-31'
            || $result['rows'][249]['date'] !== '2025-02-21') {
            throw new \InvalidArgumentException('II_IDX_SNAPSHOT_COVERAGE_MISMATCH');
        }
        return $result + [
            'source_name' => 'Dataset-Saham-IDX / Bursa Efek Indonesia',
            'source_url' => $url,
            'source_commit' => self::COMMIT,
            'source_blob' => $blob,
            'source_sha256' => hash('sha256', $raw),
            'publisher' => 'PT Bursa Efek Indonesia',
            'terms_url' => self::TERMS,
            'license_status' => 'CC_BY_NC_4_0_DECLARED_BY_MAINTAINER',
            'usage_rights' => 'NONCOMMERCIAL_USER_ATTESTED',
            'provider_identity' => $this->ticker.'@XIDX',
            'ticker' => $this->ticker,
        ];
    }

    public static function gitBlobHash(string $raw): string
    {
        return sha1('blob '.strlen($raw)."\0".$raw);
    }

    /** Parse a bounded source snapshot; never repair prices, invent missing data or adjust splits. */
    public static function normalize(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('II_IDX_SNAPSHOT_SIZE_INVALID');
        }
        $handle = fopen('php://temp', 'w+b');
        if (!$handle) {
            throw new \RuntimeException('II_IDX_SNAPSHOT_STREAM_FAILED');
        }
        try {
            fwrite($handle, $raw);
            rewind($handle);
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (!is_array($header) || count(array_unique($header)) !== count($header)) {
                throw new \InvalidArgumentException('II_IDX_SNAPSHOT_HEADER_INVALID');
            }
            $required = ['date', 'open_price', 'high', 'low', 'close', 'volume', 'listed_shares'];
            foreach ($required as $field) {
                if (!in_array($field, $header, true)) {
                    throw new \InvalidArgumentException('II_IDX_SNAPSHOT_HEADER_INVALID');
                }
            }
            $all = [];
            $previous = '';
            while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($line === [null]) {
                    continue;
                }
                if (count($line) !== count($header) || count($all) >= 2000) {
                    throw new \InvalidArgumentException('II_IDX_SNAPSHOT_ROW_INVALID');
                }
                $row = array_combine($header, $line);
                $date = $row['date'];
                $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$dt || $dt->format('Y-m-d') !== $date || $date <= $previous
                    || $date >= now('Asia/Jakarta')->toDateString()) {
                    throw new \InvalidArgumentException('II_IDX_SNAPSHOT_DATE_INVALID');
                }
                $previous = $date;
                $all[] = array_intersect_key($row, array_flip($required));
            }
            if (count($all) < 250) {
                throw new \InvalidArgumentException('II_IDX_SNAPSHOT_INSUFFICIENT_HISTORY');
            }
            $rows = [];
            $shareBasis = null;
            foreach (array_slice($all, -250) as $row) {
                foreach (array_slice($required, 1) as $field) {
                    if (!is_numeric($row[$field]) || !is_finite((float) $row[$field])) {
                        throw new \InvalidArgumentException('II_IDX_SNAPSHOT_NUMBER_INVALID');
                    }
                }
                $date = $row['date'];
                [$open, $high, $low, $close, $volume, $shares] = array_map(
                    fn ($field) => (float) $row[$field], array_slice($required, 1)
                );
                if (min($open, $high, $low, $close) <= 0 || $low > min($open, $close)
                    || $high < max($open, $close) || $volume < 0 || floor($volume) !== $volume
                    || $shares <= 0 || floor($shares) !== $shares) {
                    throw new \InvalidArgumentException('II_IDX_SNAPSHOT_OHLCV_INVALID');
                }
                if ($shareBasis !== null && $shareBasis !== $shares) {
                    throw new \InvalidArgumentException('II_IDX_SNAPSHOT_SHARE_BASIS_CHANGED');
                }
                $shareBasis = $shares;
                $rows[] = compact('date', 'open', 'high', 'low', 'close', 'volume');
            }
            return ['rows' => $rows, 'source_rows' => count($all), 'listed_shares_observed' => $shareBasis];
        } finally {
            fclose($handle);
        }
    }
}
