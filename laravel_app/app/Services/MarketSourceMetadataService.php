<?php

namespace App\Services;

/**
 * Optional source declarations. Never establishes data quality or market admission.
 * Unavailable attributes remain UNKNOWN; no inference from ticker or source name.
 */
class MarketSourceMetadataService
{
    public const SUPPORTED_TIMEZONES = [
        'UTC',
        'Asia/Jakarta',
        'Asia/Singapore',
        'Asia/Hong_Kong',
        'Asia/Tokyo',
        'Europe/London',
        'America/New_York',
        'Australia/Sydney',
    ];

    /** Sanitize validated HTML form fields before a new immutable CSV import. */
    public static function forImport(array $input): array
    {
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Mata uang harus kode tiga huruf atau dibiarkan tidak diketahui.');
        }

        $basis = strtolower(trim((string) ($input['price_adjustment'] ?? 'unknown')));
        if (!in_array($basis, ['unknown', 'adjusted', 'unadjusted'], true)) {
            throw new \InvalidArgumentException('Status penyesuaian harga tidak dikenali.');
        }

        $timezone = trim((string) ($input['session_timezone'] ?? ''));
        if ($timezone !== '' && !in_array($timezone, self::SUPPORTED_TIMEZONES, true)) {
            throw new \InvalidArgumentException('Zona waktu sumber belum didukung.');
        }

        return [
            'currency' => $currency === '' ? null : $currency,
            'price_adjustment' => $basis === 'unknown' ? null : $basis,
            'session_timezone' => $timezone === '' ? null : $timezone,
            'metadata_provenance' => 'USER_DECLARED_UNVERIFIED',
        ];
    }

    /** Legacy documents must remain readable without rewriting their bytes. */
    public static function fromDocument(array $document): array
    {
        $currency = strtoupper(trim((string) ($document['currency'] ?? '')));
        $basis = strtolower(trim((string) ($document['price_adjustment'] ?? '')));
        $timezone = trim((string) ($document['session_timezone'] ?? ''));

        return [
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'UNKNOWN',
            'price_adjustment' => in_array($basis, ['adjusted', 'unadjusted'], true) ? strtoupper($basis) : 'UNKNOWN',
            'session_timezone' => in_array($timezone, self::SUPPORTED_TIMEZONES, true) ? $timezone : 'UNKNOWN',
            'metadata_provenance' => ($document['metadata_provenance'] ?? null) === 'USER_DECLARED_UNVERIFIED'
                ? 'USER_DECLARED_UNVERIFIED' : 'NOT_DECLARED',
        ];
    }
}
