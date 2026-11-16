<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * On-demand, single-CIK SEC companyfacts API fetch.
 *
 * Fixed, canonical SEC HTTPS host only; no caller URL, redirect, scraper,
 * pagination, database writes, automated crawl or unapproved market prices.
 */
class SecOfficialCompanyFactsService
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly SecCompanyFactsNormalizationService $normalizer) {}

    public function downloadAndNormalize(string $inputCik, string $contactEmail): array
    {
        $cikInput = trim($inputCik);
        if (! preg_match('/^\d{1,10}$/D', $cikInput) || (int) $cikInput < 1) {
            throw new \InvalidArgumentException('CIK harus terdiri dari 1–10 digit dan tidak boleh nol.');
        }
        $contact = trim($contactEmail);
        if (! filter_var($contact, FILTER_VALIDATE_EMAIL) || strlen($contact) > 180
            || preg_match('/[\r\n]/', $contact)) {
            throw new \InvalidArgumentException('Email kontak SEC tidak valid.');
        }

        $cik = str_pad($cikInput, 10, '0', STR_PAD_LEFT);
        $url = 'https://data.sec.gov/api/xbrl/companyfacts/CIK'.$cik.'.json';
        // Pin trust explicitly for the Windows FrankenPHP web SAPI; do not
        // depend on cURL's different CLI/web CA autodiscovery behavior.
        $tlsOptions = self::tlsOptions();
        try {
            $response = Http::withHeaders([
                // SEC's published fair-access pattern identifies the
                // requesting research organization and a reachable contact.
                'User-Agent' => 'Investment Intelligence Research '.$contact,
                'Accept' => 'application/json',
            ])->connectTimeout(5)->timeout(15)
                ->withOptions($tlsOptions)
                ->get($url);
        } catch (ConnectionException $exception) {
            // Do not log user contact or raw exception text. Expose a useful,
            // non-sensitive root-cause category rather than vague outages.
            if (str_contains($exception->getMessage(), 'cURL error 60')) {
                throw new \InvalidArgumentException('SEC_TLS_CA_INVALID: Sertifikat CA FrankenPHP gagal diverifikasi. Jalankan pemeriksaan lingkungan II; jangan matikan verifikasi SSL.');
            }
            throw new \InvalidArgumentException('SEC_TRANSPORT_FAILURE: Koneksi SEC gagal pada runtime web. Periksa II environment doctor; data tidak disimpan.');
        }

        if ($response->status() === 404) {
            throw new \InvalidArgumentException('CIK tidak ditemukan pada API Companyfacts SEC.');
        }
        if (in_array($response->status(), [403, 429], true)) {
            throw new \InvalidArgumentException('SEC membatasi akses. Periksa identitas kontak dan aturan fair access; jangan mengulangi permintaan secara cepat.');
        }
        if ($response->status() !== 200) {
            throw new \InvalidArgumentException('API SEC mengembalikan status '.$response->status().'; tidak ada data yang disimpan.');
        }

        $length = $response->header('Content-Length');
        if ($length !== null && ctype_digit((string) $length) && (float) $length > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Respons SEC lebih besar dari 20 MB; gunakan alur pengambilan data lain yang sah.');
        }

        $mime = strtolower((string) ($response->header('Content-Type') ?? ''));
        if ($mime !== '' && ! str_contains($mime, 'application/json') && ! str_contains($mime, '+json')) {
            throw new \InvalidArgumentException('Respons SEC bukan JSON.');
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Respons SEC kosong atau lebih besar dari 20 MB.');
        }

        $normalized = $this->normalizer->convertRaw($body);
        if ($normalized['cik'] !== $cik) {
            throw new \InvalidArgumentException('CIK pada respons SEC tidak cocok dengan CIK yang diminta.');
        }

        return $normalized + [
            'fetch_origin' => 'SEC_OFFICIAL_API',
            'fetch_url' => $url,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The existing Laravel HTTP client already uses Guzzle. A runtime CA
     * bundle is the only missing web-SAPI dependency, not a new HTTP engine.
     * Explicit VERIFY path still verifies hostnames and certificate chains.
     */
    public static function tlsOptions(?string $runtimeRoot = null, ?string $sapi = null): array
    {
        $sapi ??= PHP_SAPI;
        $runtimeRoot ??= (string) (getenv('II_RUNTIME_ROOT') ?: '');
        $options = ['allow_redirects' => false, 'verify' => true];

        if ($sapi !== 'frankenphp') {
            return $options;
        }
        if (trim($runtimeRoot) === '') {
            throw new \InvalidArgumentException('SEC_TLS_CA_MISSING: II_RUNTIME_ROOT tidak tersedia pada FrankenPHP.');
        }
        $candidate = rtrim($runtimeRoot, "\\\\/ ").DIRECTORY_SEPARATOR.'certs'.DIRECTORY_SEPARATOR.'cacert.pem';
        $bundle = realpath($candidate);
        if ($bundle === false || ! is_file($bundle) || ! is_readable($bundle) || filesize($bundle) < 100000) {
            throw new \InvalidArgumentException('SEC_TLS_CA_MISSING: CA bundle Mozilla belum terpasang pada runtime FrankenPHP.');
        }
        $options['verify'] = $bundle;

        return $options;
    }
}
