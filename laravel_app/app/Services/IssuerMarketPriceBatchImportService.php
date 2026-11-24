<?php

namespace App\Services;

use App\Models\IssuerMarketPrice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Rights-aware, user-supplied end-of-day prices. No exchange scraping or API calls.
 * Imports are atomic: a bad row cannot leave accepted sibling prices behind.
 */
class IssuerMarketPriceBatchImportService
{
    private const HEADER = ['ticker', 'observed_on', 'currency', 'price', 'adjustment_status'];
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly IssuerMarketPriceService $prices) {}

    public function import(int $userId, UploadedFile $file, array $source): array
    {
        app(ByokIntakeFirewall::class)->assertIndependentSource($source);
        $raw = $this->readCsv($file);
        $sha256 = hash('sha256', $raw);
        if (IssuerMarketPrice::where('user_id', $userId)->where('batch_sha256', $sha256)->exists()) {
            throw new \InvalidArgumentException('Batch CSV dengan checksum yang sama sudah diimpor.');
        }

        $rows = $this->parse($raw);
        $name = basename($file->getClientOriginalName());
        $path = 'ii_price_batches/u'.$userId.'/'.$sha256.'.csv';
        $disk = Storage::disk('local');
        if ($disk->exists($path)) {
            throw new \InvalidArgumentException('Berkas batch dengan checksum yang sama sudah tercatat.');
        }
        if (! $disk->put($path, $raw)) {
            throw new \RuntimeException('CSV sumber tidak dapat disimpan pada storage privat.');
        }

        try {
            DB::transaction(function () use ($userId, $rows, $source, $sha256, $name): void {
                foreach ($rows as $row) {
                    $this->prices->store($userId, array_merge($source, $row, ['unit' => 'per_share']), $sha256, $name);
                }
            });
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return ['imported' => count($rows), 'sha256' => $sha256, 'storage_path' => $path];
    }

    /**
     * Non-persistent preflight for licensed/source-attested CSV onboarding.
     * All comparisons are scoped to the requesting user. No raw file, quote
     * content, or price rows are written by this method.
     */
    public function preview(int $userId, UploadedFile $file): array
    {
        $raw = $this->readCsv($file);
        $rows = $this->parse($raw);
        $sha256 = hash('sha256', $raw);
        $tickers = array_unique(array_column($rows, 'ticker'));
        $dates = array_column($rows, 'observed_on');
        $overlapCount = 0;
        $conflictCount = 0;
        $conflictExamples = [];

        foreach ($rows as $row) {
            $existing = IssuerMarketPrice::query()
                ->where('user_id', $userId)
                ->where('issuer_code', $row['ticker'])
                ->whereDate('observed_on', $row['observed_on'])
                ->get(['currency', 'price', 'adjustment_status']);
            if ($existing->isEmpty()) {
                continue;
            }
            $overlapCount++;
            $incomingFingerprint = $this->fingerprint(
                $row['currency'], $row['price'], $row['adjustment_status']
            );
            $disagrees = $existing->contains(fn (IssuerMarketPrice $price): bool =>
                $this->fingerprint($price->currency, $price->price, $price->adjustment_status) !== $incomingFingerprint
            );
            if ($disagrees) {
                $conflictCount++;
                if (count($conflictExamples) < 5) {
                    $conflictExamples[] = $row['ticker'].' / '.$row['observed_on'];
                }
            }
        }

        return [
            'rows' => count($rows),
            'tickers' => count($tickers),
            'first_date' => min($dates),
            'last_date' => max($dates),
            'sha256_prefix' => substr($sha256, 0, 16),
            'already_imported' => IssuerMarketPrice::where('user_id', $userId)
                ->where('batch_sha256', $sha256)->exists()
                || Storage::disk('local')->exists('ii_price_batches/u'.$userId.'/'.$sha256.'.csv'),
            'overlaps' => $overlapCount,
            'potential_conflicts' => $conflictCount,
            'examples' => $conflictExamples,
            'is_preview' => true,
        ];
    }

    private function fingerprint(string $currency, string $price, string $adjustment): string
    {
        $basis = strtoupper($adjustment);

        return implode('|', [
            strtoupper($currency),
            number_format((float) $price, 6, '.', ''),
            in_array($basis, ['UNADJUSTED', 'UNADJUSTED_CLOSE'], true) ? 'RAW_CLOSE' : $basis,
        ]);
    }

    private function readCsv(UploadedFile $file): string
    {
        if (! $file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'csv') {
            throw new \InvalidArgumentException('Unggah file CSV harga pasar yang valid.');
        }
        $raw = file_get_contents($file->getRealPath());
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('CSV harga pasar harus berisi data dan maksimal 2 MB.');
        }

        return $raw;
    }

    private function parse(string $raw): array
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('CSV tidak dapat diproses.');
        }
        try {
            fwrite($handle, $raw);
            rewind($handle);
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                throw new \InvalidArgumentException('Header CSV tidak ditemukan.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
            if ($header !== self::HEADER) {
                throw new \InvalidArgumentException('Header wajib ticker,observed_on,currency,price,adjustment_status.');
            }
            $rows = [];
            $seen = [];
            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) === 1 && trim((string) $values[0]) === '') {
                    continue;
                }
                if (count($values) !== count(self::HEADER)) {
                    throw new \InvalidArgumentException('Jumlah kolom CSV tidak sesuai header.');
                }
                $row = array_combine(self::HEADER, array_map('trim', $values));
                $row['ticker'] = strtoupper($row['ticker']);
                $row['currency'] = strtoupper($row['currency']);
                $row['adjustment_status'] = strtolower($row['adjustment_status']);
                if (! preg_match('/^[A-Z0-9.\-]{1,16}$/', $row['ticker'])
                    || ! in_array($row['currency'], ['IDR', 'USD'], true)
                    || ! in_array($row['adjustment_status'], ['unadjusted', 'unadjusted_close', 'split_adjusted'], true)
                    || ! preg_match('/^\d+(?:\.\d{1,6})?$/', $row['price'])
                    || (float) $row['price'] <= 0) {
                    throw new \InvalidArgumentException('Ticker, mata uang, penyesuaian, atau harga CSV tidak valid.');
                }
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['observed_on']);
                if ($date === false || $date->format('Y-m-d') !== $row['observed_on']
                    || $row['observed_on'] > now()->toDateString()) {
                    throw new \InvalidArgumentException('Tanggal harga harus YYYY-MM-DD dan tidak boleh di masa depan.');
                }
                $key = $row['ticker'].'|'.$row['observed_on'];
                if (isset($seen[$key])) {
                    throw new \InvalidArgumentException('Duplikat ticker/tanggal dalam satu CSV: '.$key);
                }
                $seen[$key] = true;
                $rows[] = $row;
                if (count($rows) > self::MAX_ROWS) {
                    throw new \InvalidArgumentException('Maksimal '.self::MAX_ROWS.' observasi harga per batch.');
                }
            }
            if ($rows === []) {
                throw new \InvalidArgumentException('CSV tidak memiliki baris harga.');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
