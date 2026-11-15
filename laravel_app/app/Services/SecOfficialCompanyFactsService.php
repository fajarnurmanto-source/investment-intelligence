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
        try {
            $response = Http::withHeaders([
                // SEC's published fair-access pattern identifies the
                // requesting research organization and a reachable contact.
                'User-Agent' => 'Investment Intelligence Research '.$contact,
                'Accept' => 'application/json',
            ])->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->get($url);
        } catch (ConnectionException) {
            throw new \InvalidArgumentException('API SEC tidak dapat dihubungi. Coba lagi kemudian atau gunakan unggah JSON manual.');
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
}
