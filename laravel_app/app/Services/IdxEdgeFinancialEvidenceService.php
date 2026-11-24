<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * IDX Edge PRO (stock.arjum.com) third-party free API pilot, not an IDX-operated feed.
 * Only source-evidence acquisition: never populate canonical issuer financial data.
 * Public docs name one ticker-scoped endpoint but do not establish the full
 * financial response schema, period semantics or redistribution rights.
 */
class IdxEdgeFinancialEvidenceService
{
    public const BASE_URL = 'https://stock.arjum.com';
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function fetch(string $inputTicker): array
    {
        $ticker = strtoupper(trim($inputTicker));
        if (! preg_match('/^[A-Z0-9]{4,6}$/D', $ticker) || str_starts_with($ticker, 'DEMO')) {
            throw new \InvalidArgumentException('IDXEDGE_TICKER_INVALID: Kode emiten harus 4–6 karakter tanpa .JK.');
        }

        $key = trim((string) config('ii.idxedge.key', ''));
        if ($key === '' || strlen($key) > 512 || preg_match('/[\r\n]/', $key)) {
            throw new \InvalidArgumentException('IDXEDGE_KEY_MISSING: Isi II_IDXEDGE_KEY pada .env runtime untuk mengaktifkan konektor.');
        }

        $url = self::BASE_URL.'/api/financial-statements/'.$ticker;
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $key,
                'Accept' => 'application/json',
            ])->connectTimeout(5)->timeout(20)
                ->withOptions(SecOfficialCompanyFactsService::tlsOptions())
                ->get($url);
        } catch (ConnectionException $e) {
            if (str_contains($e->getMessage(), 'cURL error 60')) {
                throw new \InvalidArgumentException('IDXEDGE_TLS_INVALID: Sertifikat HTTPS gagal diverifikasi; jangan nonaktifkan TLS.');
            }
            throw new \InvalidArgumentException('IDXEDGE_TRANSPORT_FAILURE: Koneksi ke penyedia tidak berhasil.');
        }

        $status = $response->status();
        if (in_array($status, [401, 403], true)) {
            throw new \InvalidArgumentException('IDXEDGE_AUTH_DENIED: Kunci belum valid atau tidak diizinkan.');
        }
        if ($status === 429) {
            throw new \InvalidArgumentException('IDXEDGE_QUOTA_EXCEEDED: Kuota atau batas laju penyedia tercapai; tidak ada retry otomatis.');
        }
        if ($status === 404) {
            throw new \InvalidArgumentException('IDXEDGE_REPORT_NOT_FOUND: Laporan untuk '.$ticker.' tidak tersedia.');
        }
        if ($status !== 200) {
            throw new \InvalidArgumentException('IDXEDGE_UPSTREAM_ERROR: Respons HTTP '.$status.' dari penyedia.');
        }

        $length = $response->header('Content-Length');
        if ($length !== null && ctype_digit((string) $length) && (float) $length > self::MAX_BYTES) {
            throw new \InvalidArgumentException('IDXEDGE_RESPONSE_TOO_LARGE: Respons melebihi 5 MB.');
        }
        $type = strtolower((string) ($response->header('Content-Type') ?? ''));
        if ($type !== '' && ! str_contains($type, 'application/json') && ! str_contains($type, '+json')) {
            throw new \InvalidArgumentException('IDXEDGE_INVALID_RESPONSE: Jenis konten bukan JSON.');
        }
        $body = $response->body();
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('IDXEDGE_INVALID_RESPONSE: Respons kosong atau lebih besar dari 5 MB.');
        }

        try {
            $payload = json_decode($body, true, 48, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('IDXEDGE_INVALID_RESPONSE: JSON dari penyedia tidak valid.');
        }
        if (! is_array($payload) || $payload === [] || ($payload['success'] ?? true) === false
            || array_key_exists('error', $payload)
            || (array_key_exists('data', $payload) && ($payload['data'] === null || $payload['data'] === []))) {
            throw new \InvalidArgumentException('IDXEDGE_INVALID_RESPONSE: Penyedia tidak mengembalikan laporan yang dapat ditinjau.');
        }

        // Validate identity only if supplied by the upstream API. Unknown
        // schema must stay UNVERIFIED, never fabricated or auto-reconciled.
        $observed = [];
        foreach (['stock_code', 'code', 'symbol', 'ticker'] as $field) {
            if (isset($payload[$field])) {
                if (! is_string($payload[$field])
                    || strtoupper(trim($payload[$field])) !== $ticker) {
                    throw new \InvalidArgumentException('IDXEDGE_ISSUER_MISMATCH: Kode emiten pada respons berbeda.');
                }
                $observed[] = $field;
            }
        }
        if (str_contains($body, $key)) {
            throw new \InvalidArgumentException('IDXEDGE_CREDENTIAL_ECHO: Penyedia mengembalikan kredensial; respons ditolak.');
        }

        return [
            'provider' => 'IDX Edge PRO (third-party, not IDX/BEI)',
            'source_url' => $url,
            'documentation_url' => self::BASE_URL.'/api-docs',
            'ticker_requested' => $ticker,
            'observed_identity_fields' => $observed,
            'identity_status' => $observed === [] ? 'UNVERIFIED_MISSING_TICKER' : 'MATCHED_PROVIDER_CLAIM',
            'financial_period_status' => 'UNVERIFIED',
            'currency_status' => 'UNVERIFIED',
            'source_sha256' => hash('sha256', $body),
            'fetched_at' => now()->toIso8601String(),
            'quota_remaining_claim' => ctype_digit((string) ($response->header('X-RateLimit-Remaining') ?? ''))
                ? (int) $response->header('X-RateLimit-Remaining') : null,
            'raw_provider_payload' => $payload,
            'status' => 'SCHEMA_REVIEW_REQUIRED',
            'numeric_reconciliation' => 'UNRECONCILED',
            'gate' => 'NOT_ADMITTED',
            'decision' => 'HOLD',
            'trading_authority' => 'NONE',
            'redistribution_allowed' => false,
            'automatic_database_import' => false,
        ];
    }
}
