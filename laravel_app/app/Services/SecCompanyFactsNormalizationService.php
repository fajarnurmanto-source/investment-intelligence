<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

/**
 * Offline, deterministic US-GAAP annual companyfacts normalization.
 * The user supplies a JSON file downloaded lawfully from data.sec.gov.
 * This is NOT SEC's endorsement, independent numeric reconciliation, or a
 * market price feed. No write operations, arbitrary HTTP fetches, or secrets.
 */
class SecCompanyFactsNormalizationService
{
    private const TAGS = [
        'revenue' => ['RevenueFromContractWithCustomerExcludingAssessedTax', 'Revenues', 'SalesRevenueNet'],
        'net_income_attributable' => ['NetIncomeLoss'],
        'total_assets' => ['Assets'],
        'total_equity' => ['StockholdersEquityIncludingPortionAttributableToNoncontrollingInterest'],
        'equity_attributable' => ['StockholdersEquity'],
        'operating_cash_flow' => ['NetCashProvidedByUsedInOperatingActivities'],
        'shares_outstanding' => ['CommonStockSharesOutstanding'],
        'cash_and_equivalents' => ['CashAndCashEquivalentsAtCarryingValue'],
    ];

    private const INSTANT = ['total_assets', 'total_equity', 'equity_attributable', 'shares_outstanding', 'cash_and_equivalents'];

    public function convert(UploadedFile $file): array
    {
        if (! $file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'json') {
            throw new \InvalidArgumentException('Unggah berkas SEC companyfacts berformat JSON yang valid.');
        }

        $raw = file_get_contents($file->getRealPath());
        if (! is_string($raw)) {
            throw new \InvalidArgumentException('Berkas JSON tidak dapat dibaca.');
        }

        return $this->convertRaw($raw);
    }

    /**
     * Same exact normalized CSV source for offline uploads and sanctioned
     * official API responses. No DB or storage mutations.
     */
    public function convertRaw(string $raw): array
    {
        if ($raw === '' || strlen($raw) > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('Berkas JSON kosong atau melebihi 20 MB.');
        }

        try {
            $json = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Companyfacts JSON tidak valid.');
        }

        if (! is_array($json) || ! preg_match('/^\d{1,10}$/', (string) ($json['cik'] ?? ''))
            || ! is_string($json['entityName'] ?? null) || ! is_array($json['facts']['us-gaap'] ?? null)) {
            throw new \InvalidArgumentException('Berkas bukan SEC companyfacts US-GAAP yang didukung.');
        }
        $cik = str_pad((string) $json['cik'], 10, '0', STR_PAD_LEFT);
        $facts = $json['facts']['us-gaap'];

        // Only keep annual duration/instant facts reported in 10-K/10-K/A.
        // Values from different accessions must NEVER be synthesized into one
        // nominal fiscal-period snapshot.
        $groups = [];
        foreach (self::TAGS as $metric => $tags) {
            foreach ($tags as $tag) {
                $units = $facts[$tag]['units'] ?? null;
                if (! is_array($units)) {
                    continue;
                }
                $unit = $metric === 'shares_outstanding' ? 'shares' : 'USD';
                foreach (($units[$unit] ?? []) as $fact) {
                    if (! is_array($fact) || ! in_array($fact['form'] ?? null, ['10-K', '10-K/A'], true)
                        || ($fact['fp'] ?? null) !== 'FY'
                        || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($fact['end'] ?? ''))
                        || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($fact['filed'] ?? ''))
                        || ($fact['filed'] > date('Y-m-d'))
                        || ! preg_match('/^\d{10}-\d{2}-\d{6}$/', (string) ($fact['accn'] ?? ''))) {
                        continue;
                    }

                    $instant = in_array($metric, self::INSTANT, true);
                    if ($instant && isset($fact['start'])) {
                        continue;
                    }
                    if (! $instant) {
                        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($fact['start'] ?? ''));
                        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $fact['end']);
                        if (! $start || ! $end || (int) $start->diff($end)->format('%r%a') < 330
                            || (int) $start->diff($end)->format('%r%a') > 400) {
                            continue;
                        }
                    }
                    if (! isset($fact['val']) || ! is_numeric($fact['val'])
                        || ! is_finite((float) $fact['val']) || abs((float) $fact['val']) > 9e14) {
                        continue;
                    }
                    if (in_array($metric, ['total_assets', 'shares_outstanding'], true) && (float) $fact['val'] <= 0) {
                        continue;
                    }
                    if ($metric === 'cash_and_equivalents' && (float) $fact['val'] < 0) {
                        continue;
                    }

                    $key = $fact['end'].'|'.$fact['accn'];
                    $groups[$key] ??= [
                        'end' => $fact['end'], 'filed' => $fact['filed'], 'accn' => $fact['accn'],
                        'facts' => [],
                    ];
                    if ($fact['filed'] > $groups[$key]['filed']) {
                        $groups[$key]['filed'] = $fact['filed'];
                    }
                    $groups[$key]['facts'][$metric][$tag][] = $fact['val'];
                }
            }
        }

        $years = [];
        foreach ($groups as $group) {
            $year = (int) substr($group['end'], 0, 4);
            if ($year < 2000 || $year > (int) date('Y')) {
                continue;
            }
            $metrics = [];
            foreach (self::TAGS as $metric => $tags) {
                $metrics[$metric] = null;
                // Prefer a single unambiguous tag (from the declared mapping
                // order). If the same tag yields contradictory observations
                // for an identical accession/end, fail closed on that metric.
                foreach ($tags as $tag) {
                    $values = $group['facts'][$metric][$tag] ?? [];
                    if ($values === []) {
                        continue;
                    }
                    $unique = array_unique(array_map('strval', $values));
                    if (count($unique) === 1) {
                        $metrics[$metric] = (string) reset($unique);
                    }
                    break;
                }
            }
            if ($metrics['revenue'] === null && $metrics['total_assets'] === null) {
                continue;
            }
            $group['metrics'] = $metrics;
            $group['field_count'] = count(array_filter($metrics, fn ($v) => $v !== null));
            // Pick one period per calendar end year. Prefer the last period
            // date, then the most recent filing; never mix accession facts.
            $previous = $years[$year] ?? null;
            if ($previous === null
                || [$group['end'], $group['filed'], $group['field_count'], $group['accn']]
                   > [$previous['end'], $previous['filed'], $previous['field_count'], $previous['accn']]) {
                $years[$year] = $group;
            }
        }
        if ($years === []) {
            throw new \InvalidArgumentException('Tidak ditemukan fakta tahunan 10-K US-GAAP yang dapat dinormalisasi. IFRS dan formulir 10-Q belum didukung.');
        }

        krsort($years, SORT_NUMERIC);
        $years = array_slice($years, 0, 5, true);
        ksort($years, SORT_NUMERIC);
        $rows = [];
        foreach ($years as $year => $group) {
            $rows[] = [
                'fiscal_year' => $year, 'period_end' => $group['end'], 'restated' => 'false',
                'revenue' => $group['metrics']['revenue'],
                'net_income_attributable' => $group['metrics']['net_income_attributable'],
                'total_assets' => $group['metrics']['total_assets'],
                'total_equity' => $group['metrics']['total_equity'],
                'equity_attributable' => $group['metrics']['equity_attributable'],
                'operating_cash_flow' => $group['metrics']['operating_cash_flow'],
                'shares_outstanding' => $group['metrics']['shares_outstanding'],
                // Cannot infer financing debt, issuer-specific EBITDA, or EOD
                // prices from generic SEC tag equivalences.
                'total_debt' => null, 'cash_and_equivalents' => $group['metrics']['cash_and_equivalents'],
                'ebitda' => null, 'market_price' => null, 'market_price_as_of' => null,
            ];
        }

        return [
            'cik' => $cik,
            'entity_name' => substr($json['entityName'], 0, 200),
            'source_url' => 'https://data.sec.gov/api/xbrl/companyfacts/CIK'.$cik.'.json',
            'source_sha256' => hash('sha256', $raw),
            'periods' => $rows,
            'period_count' => count($rows),
            'has_revenue' => count(array_filter($rows, fn ($r) => $r['revenue'] !== null)),
            'has_assets' => count(array_filter($rows, fn ($r) => $r['total_assets'] !== null)),
            'requires_user_reconciliation' => true,
            'external_fetch_performed' => false,
        ];
    }
}
