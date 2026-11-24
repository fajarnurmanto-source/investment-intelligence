<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only, no-scraping third-party IDX fundamental API connector.
 * idxapi.io is not the Indonesia Stock Exchange and does not confer an IDX
 * redistribution licence. All results are for private review, not admission.
 */
class IdxApiFreeFundamentalsService
{
    private const HOST = 'https://api.idxapi.io';

    private const PATHS = [
        'income' => '/v1/income-statement',
        'balance' => '/v1/balance-sheet-statement',
        'cash_flow' => '/v1/cash-flow-statement',
    ];

    private const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    public function fetchAnnual(string $inputTicker, int $year): array
    {
        $ticker = strtoupper(trim($inputTicker));
        if (! preg_match('/^[A-Z0-9]{4,6}$/D', $ticker) || str_starts_with($ticker, 'DEMO')) {
            throw new \InvalidArgumentException('IDXAPI_TICKER_INVALID: Gunakan kode emiten BEI 4–6 karakter, tanpa .JK.');
        }
        if ($year < 2000 || $year > (int) date('Y')) {
            throw new \InvalidArgumentException('IDXAPI_YEAR_INVALID: Tahun laporan tidak valid.');
        }

        $key = trim((string) config('ii.idxapi.key', ''));
        if ($key === '' || strlen($key) > 512 || preg_match('/[\r\n]/', $key)) {
            throw new \InvalidArgumentException('IDXAPI_KEY_MISSING: Daftarkan API key gratis, lalu isi II_IDXAPI_KEY di .env runtime II.');
        }

        // Preserve the proven Windows FrankenPHP TLS setup and verification.
        $options = SecOfficialCompanyFactsService::tlsOptions();
        $snapshots = [];
        $currency = null;
        $scale = null;
        $accession = null;
        foreach (self::PATHS as $kind => $path) {
            $query = [
                'symbol' => $ticker,
                'period' => 'annual',
                'calendarYear' => $year,
                'fiscalPeriod' => 'FY',
                'labelsVersion' => 'idx',
                'envelope' => 'full',
                'include' => 'currency,scale,provenance',
            ];
            try {
                $response = Http::withToken($key)
                    ->acceptJson()->connectTimeout(5)->timeout(20)
                    ->withOptions($options + ['allow_redirects' => false])
                    ->get(self::HOST.$path, $query);
            } catch (ConnectionException $exception) {
                if (str_contains($exception->getMessage(), 'cURL error 60')) {
                    throw new \InvalidArgumentException('IDXAPI_TLS_INVALID: Sertifikat HTTPS tidak dipercaya; verifikasi TLS tidak boleh dimatikan.');
                }
                throw new \InvalidArgumentException('IDXAPI_NETWORK_FAILURE: Koneksi layanan data gagal.');
            }

            if (in_array($response->status(), [401, 403], true)) {
                throw new \InvalidArgumentException('IDXAPI_AUTH_DENIED: API key tidak valid atau belum memiliki izin.');
            }
            if ($response->status() === 429) {
                throw new \InvalidArgumentException('IDXAPI_QUOTA_EXCEEDED: Batas API gratis tercapai; jangan ulangi otomatis.');
            }
            if ($response->status() === 404) {
                throw new \InvalidArgumentException('IDXAPI_NOT_AVAILABLE: Laporan '.$ticker.' FY '.$year.' tidak tersedia pada penyedia.');
            }
            if ($response->status() !== 200) {
                throw new \InvalidArgumentException('IDXAPI_UPSTREAM_HTTP_'.$response->status().': Sumber belum dapat digunakan.');
            }
            $length = $response->header('Content-Length');
            if ($length !== null && ctype_digit((string) $length) && (float) $length > self::MAX_RESPONSE_BYTES) {
                throw new \InvalidArgumentException('IDXAPI_RESPONSE_TOO_LARGE: Ukuran respons melebihi 2 MB.');
            }
            $mime = strtolower((string) ($response->header('Content-Type') ?? ''));
            if ($mime !== '' && ! str_contains($mime, 'application/json') && ! str_contains($mime, '+json')) {
                throw new \InvalidArgumentException('IDXAPI_INVALID_RESPONSE: Respons bukan JSON.');
            }
            $body = $response->body();
            if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new \InvalidArgumentException('IDXAPI_INVALID_RESPONSE: Respons kosong atau terlalu besar.');
            }
            try {
                $rows = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                throw new \InvalidArgumentException('IDXAPI_INVALID_RESPONSE: JSON tidak valid.');
            }
            // The documented endpoint responds with a period array. Demand
            // the exact requested FY instead of guessing fiscal dates.
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1
                || ! is_array($rows[0])) {
                throw new \InvalidArgumentException('IDXAPI_SCHEMA_CHANGED: Periode tahunan tidak tunggal.');
            }
            $row = $rows[0];
            if (strtoupper((string) ($row['symbol'] ?? '')) !== $ticker
                || (string) ($row['calendarYear'] ?? '') !== (string) $year
                || ($row['period'] ?? '') !== 'FY'
                || ! is_array($row['data'] ?? null) || count($row['data']) === 0
                || count($row['data']) > 500
                || ! is_string($row['filingDate'] ?? null)
                || ! self::validDate($row['filingDate'])) {
                throw new \InvalidArgumentException('IDXAPI_SCHEMA_CHANGED: Identitas emiten, periode, atau isi laporan tidak cocok.');
            }
            $reportedCurrency = strtoupper((string) ($row['reportedCurrency'] ?? ''));
            $scaleMultiplier = $row['scaleMultiplier'] ?? null;
            if (! in_array($reportedCurrency, ['IDR', 'USD'], true)
                || ! in_array((string) $scaleMultiplier, ['1', '1000', '1000000', '1000000000'], true)) {
                throw new \InvalidArgumentException('IDXAPI_SCALE_UNKNOWN: Mata uang atau skala belum terverifikasi.');
            }
            if ($currency !== null && $currency !== $reportedCurrency) {
                throw new \InvalidArgumentException('IDXAPI_CURRENCY_CONFLICT: Laporan berbeda mata uang.');
            }
            if ($scale !== null && $scale !== (string) $scaleMultiplier) {
                throw new \InvalidArgumentException('IDXAPI_SCALE_CONFLICT: Laporan berbeda skala.');
            }

            // Reject inconsistent filing identity rather than silently
            // combining statements from different original filings.
            $provenance = $row['provenance'] ?? null;
            if (! is_array($provenance) || ! is_string($provenance['accessionNumber'] ?? null)
                || trim($provenance['accessionNumber']) === '') {
                throw new \InvalidArgumentException('IDXAPI_PROVENANCE_MISSING: Nomor akses dokumen tidak tersedia.');
            }
            $reference = trim($provenance['accessionNumber']);
            if ($accession !== null && $accession !== $reference) {
                throw new \InvalidArgumentException('IDXAPI_FILING_CONFLICT: Identitas dokumen antar laporan tidak sama.');
            }
            foreach ($row['data'] as $concept => $value) {
                if (! is_string($concept) || $concept === ''
                    || (! is_int($value) && ! is_float($value) && ! is_string($value) && $value !== null)
                    || (is_string($value) && $value !== '' && ! is_numeric($value))) {
                    throw new \InvalidArgumentException('IDXAPI_DATA_INVALID: Konsep atau angka keuangan tidak valid.');
                }
            }
            $currency = $reportedCurrency;
            $scale = (string) $scaleMultiplier;
            $accession = $reference;
            $snapshots[$kind] = [
                'endpoint' => self::HOST.$path,
                'source_sha256' => hash('sha256', $body),
                'filing_date' => $row['filingDate'],
                'reported_currency' => $reportedCurrency,
                'scale_multiplier' => $scale,
                'provenance' => $provenance,
                // Keep native source concepts intact. Do NOT equate an IDX
                // label to II's canonical financial metric without a taxonomy
                // mapping and source validation.
                'reported_line_items' => $row['data'],
            ];
        }

        return [
            'provider' => 'idxapi.io (third-party, NOT official IDX API)',
            'origin' => 'IDXAPI_FREE_TIER',
            'source_documentation' => 'https://idxapi.io/docs',
            'terms_url' => 'https://idxapi.io/terms',
            'ticker' => $ticker,
            'year' => $year,
            'period' => 'FY',
            'currency' => $currency,
            'scale_multiplier' => $scale,
            'accession' => $accession,
            'fetched_at' => now()->toIso8601String(),
            'api_requests' => count(self::PATHS),
            'statements' => $snapshots,
            'status' => 'REVIEW_REQUIRED',
            'numeric_reconciliation' => 'UNRECONCILED',
            'source_authenticity' => 'THIRD_PARTY_UNVERIFIED',
            'gate' => 'NOT_ADMITTED',
            'decision' => 'HOLD',
            'trading_authority' => 'NONE',
            'automatic_database_import' => false,
            'redistribution_allowed' => false,
        ];
    }

    private static function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value && $value <= date('Y-m-d');
    }
}
