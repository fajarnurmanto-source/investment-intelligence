<?php

namespace App\Services;

/**
 * Read-only provenance and comparability checks on user-scoped OHLCV sources.
 * This is not source certification or market-data verification.
 */
class PortfolioDataQualityService
{
    public function audit(array $documents, array $commonDates): array
    {
        $union = [];
        $assetDetails = [];
        $currencies = [];
        $timezones = [];
        $adjustmentBases = [];
        $unknownCurrency = false;
        $unknownTimezone = false;
        $warnings = [];
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Jakarta'));

        foreach ($documents as $ticker => $document) {
            $prices = [];
            foreach ($document['rows'] as $row) {
                $date = (string) ($row['date'] ?? '');
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Jakarta'));
                if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                    throw new \InvalidArgumentException('Format tanggal pada instrumen '.$ticker.' tidak valid.');
                }
                $prices[$date] = (float) $row['close'];
                $union[$date] = true;
            }
            ksort($prices, SORT_STRING);
            $dates = array_keys($prices);
            $firstDate = $dates[0];
            $lastDate = $dates[count($dates) - 1];
            $latest = \DateTimeImmutable::createFromFormat('!Y-m-d', $lastDate, new \DateTimeZone('Asia/Jakarta'));
            $ageDays = (int) $today->diff($latest)->days;
            $future = $latest > $today;
            $stale = $future || $ageDays > 10;

            $jumps = 0;
            $maxJump = 0.0;
            $maxGap = 0;
            $previousPrice = null;
            $previousDate = null;
            foreach ($prices as $date => $price) {
                if ($previousPrice !== null) {
                    $jumpPct = abs(($price / $previousPrice) - 1.0) * 100;
                    if (!is_finite($jumpPct)) {
                        throw new \InvalidArgumentException('Skala perubahan harga tidak valid untuk audit instrumen '.$ticker.'.');
                    }
                    $maxJump = max($maxJump, $jumpPct);
                    if ($jumpPct > 30) {
                        $jumps++;
                    }
                    $current = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Jakarta'));
                    $gap = (int) $previousDate->diff($current)->days;
                    $maxGap = max($maxGap, $gap);
                }
                $previousPrice = $price;
                $previousDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Jakarta'));
            }

            $sourceMetadata = MarketSourceMetadataService::fromDocument($document);
            $currency = $sourceMetadata['currency'];
            if ($currency === 'UNKNOWN') {
                $unknownCurrency = true;
                $warnings[] = 'Mata uang tidak dinyatakan pada seluruh atau sebagian seri; perbandingan lintas mata uang belum dapat dipastikan.';
            } else {
                $currencies[$currency] = true;
            }

            $priceBasis = $sourceMetadata['price_adjustment'];
            if ($priceBasis === 'UNKNOWN') {
                $warnings[] = 'Status penyesuaian harga atas split/dividen belum dinyatakan oleh sumber.';
            } else {
                $adjustmentBases[$priceBasis] = true;
            }
            if ($priceBasis === 'UNADJUSTED') {
                $warnings[] = 'Terdapat seri harga yang ditandai belum disesuaikan terhadap aksi korporasi.';
            }

            $timezone = $sourceMetadata['session_timezone'];
            if ($timezone === 'UNKNOWN') {
                $unknownTimezone = true;
                $warnings[] = 'Zona waktu sesi sumber belum dideklarasikan; keselarasan waktu penutupan antar-pasar belum dapat diperiksa.';
            } else {
                $timezones[$timezone] = true;
            }

            if ($stale) {
                $warnings[] = 'Setidaknya satu instrumen memiliki tanggal terakhir terlalu lama atau berada di masa depan.';
            }
            if ($jumps > 0) {
                $warnings[] = 'Ditemukan lonjakan close lebih dari 30% antar-observasi. Periksa split, aksi korporasi, kesalahan sumber, atau peristiwa pasar.';
            }
            if ($maxGap > 10) {
                $warnings[] = 'Terdapat jeda lebih dari 10 hari kalender dalam salah satu seri.';
            }
            if (($document['kind'] ?? '') === 'synthetic') {
                $warnings[] = 'Data sintetis terlibat; hasil tidak dapat digunakan sebagai validasi perilaku pasar nyata.';
            }

            $assetDetails[] = [
                'ticker' => $ticker,
                'rows' => count($prices),
                'common_coverage_pct' => round(100 * count($commonDates) / count($prices), 1),
                'first_date' => $firstDate,
                'last_date' => $lastDate,
                'age_days' => $ageDays,
                'future_date' => $future,
                'stale' => $stale,
                'currency' => $currency,
                'price_adjustment' => $priceBasis,
                'session_timezone' => $timezone,
                'metadata_provenance' => $sourceMetadata['metadata_provenance'],
                'large_jumps' => $jumps,
                'max_abs_jump_pct' => round($maxJump, 2),
                'max_calendar_gap_days' => $maxGap,
            ];
        }

        $unionCount = count($union);
        $overlap = round(100 * count($commonDates) / $unionCount, 1);
        if ($overlap < 60) {
            $warnings[] = 'Cakupan tanggal bersama kurang dari 60% seluruh tanggal gabungan. Banyak histori sumber tidak masuk dalam perbandingan.';
        }

        $currencyList = array_keys($currencies);
        sort($currencyList, SORT_STRING);
        $currencyMismatch = count($currencyList) > 1;
        if ($currencyMismatch) {
            $warnings[] = 'Mata uang berbeda terdeteksi. Tidak ada konversi nilai tukar; kalkulasi portofolio diblokir.';
        }
        if (count($timezones) > 1) {
            $warnings[] = 'Zona waktu sesi berbeda; tanggal kalender yang sama belum menjamin informasi tersedia pada saat yang sama (potensi look-ahead).';
        }
        if (count($adjustmentBases) > 1) {
            $warnings[] = 'Seri adjusted dan unadjusted tercampur; angka return tidak setara dan memerlukan peninjauan aksi korporasi.';
        }

        return [
            'state' => 'REVIEW_REQUIRED',
            'admitted' => false,
            'union_dates' => $unionCount,
            'common_dates' => count($commonDates),
            'overall_overlap_pct' => $overlap,
            'currency_status' => $currencyMismatch ? 'MISMATCH' : ($unknownCurrency ? 'UNCONFIRMED' : 'DECLARED_SAME'),
            'currency_mismatch' => $currencyMismatch,
            'timezone_status' => count($timezones) > 1 ? 'DECLARED_DIFFERENT' : ($unknownTimezone ? 'UNCONFIRMED' : 'DECLARED_SAME'),
            'assets' => $assetDetails,
            'warnings' => array_values(array_unique($warnings)),
            'disclaimer' => 'Pemeriksaan otomatis hanya menemukan potensi masalah; tidak mensertifikasi keaslian, ketepatan harga, mata uang, atau aksi korporasi.',
        ];
    }
}
