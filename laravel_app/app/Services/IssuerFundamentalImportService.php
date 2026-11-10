<?php

namespace App\Services;

use App\Models\IssuerDocument;
use App\Models\IssuerSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IssuerFundamentalImportService
{
    private const METRICS = ['revenue', 'net_income_attributable', 'total_assets', 'equity_attributable',
        'operating_cash_flow', 'shares_outstanding', 'total_debt', 'cash_and_equivalents', 'ebitda', 'market_price'];

    public function __construct(private readonly XbrlFundamentalParserService $xbrl) {}

    public function import(int $userId, array $metadata, UploadedFile $file): IssuerDocument
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xbrl', 'xml', 'pdf'], true)) {
            throw new \InvalidArgumentException('Format yang didukung: CSV, XBRL/XML, atau PDF.');
        }
        $sha256 = hash_file('sha256', $file->getRealPath());
        if (IssuerDocument::where('user_id', $userId)->where('sha256', $sha256)->exists()) {
            throw new \InvalidArgumentException('Dokumen dengan checksum yang sama sudah tersimpan; versi lama tidak ditimpa.');
        }

        $periods = match ($extension) {
            'csv' => $this->parseCsv($file->getRealPath()),
            'xbrl', 'xml' => $this->xbrl->parse($file->getRealPath()),
            default => [],
        };
        $quality = $this->quality($periods, $metadata);
        $id = (string) Str::uuid();
        $path = 'ii_issuer_documents/u'.$userId.'/'.$id.'.'.$extension;
        if (! Storage::disk('local')->put($path, file_get_contents($file->getRealPath()))) {
            throw new \RuntimeException('Dokumen sumber tidak dapat disimpan.');
        }

        try {
            return DB::transaction(function () use ($userId, $metadata, $file, $extension, $sha256, $periods, $quality, $id, $path): IssuerDocument {
                $sourceKey = hash('sha256', json_encode([
                    $metadata['source_name'], $metadata['source_url'] ?? null, $metadata['terms_url'] ?? null,
                    $metadata['license_status'], $metadata['usage_rights'], (bool) ($metadata['redistribution_allowed'] ?? false),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $source = IssuerSource::firstOrCreate([
                    'user_id' => $userId, 'source_key' => $sourceKey,
                ], [
                    'name' => $metadata['source_name'], 'source_url' => $metadata['source_url'] ?? null,
                    'publisher' => $metadata['publisher'] ?? null,
                    'terms_url' => $metadata['terms_url'] ?? null,
                    'license_status' => $metadata['license_status'],
                    'usage_rights' => $metadata['usage_rights'],
                    'redistribution_allowed' => (bool) ($metadata['redistribution_allowed'] ?? false),
                ]);
                $document = IssuerDocument::create([
                    'id' => $id, 'user_id' => $userId, 'source_id' => $source->id,
                    'issuer_code' => strtoupper($metadata['ticker']), 'issuer_name' => $metadata['issuer_name'],
                    'report_period' => $metadata['report_period'], 'report_date' => $metadata['report_date'],
                    'currency' => $metadata['currency'], 'unit_multiplier' => (int) $metadata['multiplier'],
                    'consolidated' => (bool) ($metadata['consolidated'] ?? false),
                    'format' => $extension === 'xml' ? 'xbrl' : $extension,
                    'original_filename' => basename($file->getClientOriginalName()), 'storage_path' => $path,
                    'sha256' => $sha256, 'restates_document_id' => $metadata['restates_document_id'] ?? null,
                    'quality_status' => $quality['status'], 'quality_score' => $quality['score'],
                    'provenance' => [
                        'source_url' => $metadata['source_url'] ?? null, 'terms_url' => $metadata['terms_url'] ?? null,
                        'license_status' => $metadata['license_status'], 'usage_rights' => $metadata['usage_rights'],
                        'redistribution_allowed' => (bool) ($metadata['redistribution_allowed'] ?? false),
                        'sha256' => $sha256, 'original_filename' => basename($file->getClientOriginalName()),
                        'reported_by_user' => true,
                    ],
                    'imported_at' => now(),
                ]);
                foreach ($periods as $period) {
                    $normalized = $this->normalizePeriod($period, (int) $metadata['multiplier']);
                    $document->periods()->create($normalized);
                }

                return $document->load(['source', 'periods']);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('CSV tidak dapat dibaca.');
        }
        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                throw new \InvalidArgumentException('CSV kosong.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(fn ($v) => strtolower(trim((string) $v)), $header);
            $allowed = array_merge(['fiscal_year', 'year', 'period_end', 'restated'], self::METRICS);
            if (! in_array('fiscal_year', $header, true) && ! in_array('year', $header, true)) {
                throw new \InvalidArgumentException('CSV wajib memiliki fiscal_year (atau year).');
            }
            if (array_diff($header, $allowed)) {
                throw new \InvalidArgumentException('CSV memuat kolom yang tidak dikenal.');
            }
            $rows = [];
            $seen = [];
            while (($line = fgetcsv($handle)) !== false) {
                if (count($line) === 1 && trim((string) $line[0]) === '') {
                    continue;
                }
                if (count($line) !== count($header)) {
                    throw new \InvalidArgumentException('Jumlah kolom CSV tidak konsisten.');
                }
                $row = array_combine($header, $line);
                $year = trim((string) ($row['fiscal_year'] ?? $row['year'] ?? ''));
                if (! preg_match('/^(19|20)\d{2}$/', $year) || isset($seen[$year])) {
                    throw new \InvalidArgumentException('Tahun fiskal tidak valid atau duplikat: '.$year);
                }
                $seen[$year] = true;
                $parsed = ['fiscal_year' => (int) $year, 'period_end' => trim((string) ($row['period_end'] ?? '')) ?: $year.'-12-31',
                    'restated' => filter_var($row['restated'] ?? false, FILTER_VALIDATE_BOOL)];
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $parsed['period_end']);
                if (! $date || $date->format('Y-m-d') !== $parsed['period_end']) {
                    throw new \InvalidArgumentException('period_end harus berformat YYYY-MM-DD.');
                }
                foreach (self::METRICS as $metric) {
                    $value = trim((string) ($row[$metric] ?? ''));
                    if ($value === '') {
                        $parsed[$metric] = null;

                        continue;
                    }
                    if (! is_numeric($value) || ! is_finite((float) $value)) {
                        throw new \InvalidArgumentException($metric.' harus berupa angka desimal tanpa pemisah ribuan.');
                    }
                    $parsed[$metric] = (float) $value;
                }
                if (($parsed['shares_outstanding'] ?? 1) <= 0 || ($parsed['total_assets'] ?? 1) <= 0) {
                    throw new \InvalidArgumentException('Assets dan saham beredar, bila tersedia, harus positif.');
                }
                if (($parsed['market_price'] ?? 1) <= 0 || ($parsed['total_debt'] ?? 0) < 0 || ($parsed['cash_and_equivalents'] ?? 0) < 0) {
                    throw new \InvalidArgumentException('Harga harus positif; utang dan kas tidak boleh negatif.');
                }
                $rows[] = $parsed;
                if (count($rows) > 20) {
                    throw new \InvalidArgumentException('Maksimal 20 periode per impor.');
                }
            }
            if ($rows === []) {
                throw new \InvalidArgumentException('CSV tidak memuat periode data.');
            }
            usort($rows, fn ($a, $b) => [$a['fiscal_year'], $a['period_end']] <=> [$b['fiscal_year'], $b['period_end']]);

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function normalizePeriod(array $period, int $multiplier): array
    {
        $available = [];
        foreach (self::METRICS as $metric) {
            $value = $period[$metric] ?? null;
            if ($value !== null) {
                $available[] = $metric;
            }
            $period[$metric] = $value === null ? null : (string) ($metric === 'shares_outstanding' || $metric === 'market_price' ? $value : $value * $multiplier);
        }
        $period['available_fields'] = $available;

        return array_intersect_key($period, array_flip(array_merge(['fiscal_year', 'period_end', 'restated', 'available_fields'], self::METRICS)));
    }

    private function quality(array $periods, array $metadata): array
    {
        if ($periods === []) {
            return ['status' => 'DOCUMENT_ONLY', 'score' => 25];
        }
        $required = ['revenue', 'net_income_attributable', 'total_assets', 'equity_attributable', 'operating_cash_flow', 'shares_outstanding'];
        $latest = end($periods);
        $present = count(array_filter($required, fn ($field) => ($latest[$field] ?? null) !== null));
        $score = (int) round(15 + 70 * $present / count($required));
        if (($metadata['source_url'] ?? null) && ($metadata['usage_rights'] ?? 'unknown') !== 'unknown') {
            $score += 15;
        }

        return ['status' => $present === count($required) ? 'COMPLETE' : 'PARTIAL', 'score' => min(100, $score)];
    }
}
