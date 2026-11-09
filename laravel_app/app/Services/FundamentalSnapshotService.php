<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/** Research-only issuer fundamentals; local user-imported information is not admitted evidence. */
class FundamentalSnapshotService
{
    private function directory(int $userId): string
    {
        return 'ii_fundamental_hub/u'.$userId;
    }

    public function save(int $userId, array $document): void
    {
        $ticker = $document['ticker'];
        $path = $this->directory($userId).'/'.$ticker.'__'.bin2hex(random_bytes(12)).'.json';
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (!Storage::disk('local')->put($path, $json)) {
            throw new \RuntimeException('Could not store issuer data.');
        }
    }

    public function latestByTicker(int $userId): array
    {
        $disk = Storage::disk('local');
        $latest = [];
        foreach ($disk->files($this->directory($userId)) as $path) {
            if (!str_ends_with($path, '.json')) {
                continue;
            }
            try {
                $doc = json_decode($disk->get($path), true);
            } catch (\Throwable $exception) {
                continue;
            }
            if (!is_array($doc) || !preg_match('/^[A-Z0-9.\-]{1,16}$/', (string) ($doc['ticker'] ?? ''))
                || !is_array($doc['years'] ?? null) || count($doc['years']) < 2
                || !in_array(($doc['schema'] ?? ''), ['ii.fundamentals.vs7.r2'], true)) {
                continue;
            }
            $ticker = $doc['ticker'];
            if (!isset($latest[$ticker]) || strcmp((string) $doc['imported_at'], (string) $latest[$ticker]['imported_at']) > 0) {
                $latest[$ticker] = $doc;
            }
        }
        $out = [];
        foreach ($latest as $ticker => $doc) {
            $out[$ticker] = $this->summarize($doc);
        }
        ksort($out);
        return $out;
    }

    private function pct(float $numerator, float $denominator): ?float
    {
        return $denominator > 0 ? round(100 * $numerator / $denominator, 2) : null;
    }

    private function summarize(array $doc): array
    {
        $years = $doc['years'];
        usort($years, fn (array $a, array $b) => $a['year'] <=> $b['year']);
        $last = $years[count($years) - 1];
        $prev = $years[count($years) - 2];
        $multiplier = (float) $doc['multiplier'];

        $annual = [];
        foreach ($years as $row) {
            $annual[] = [
                'year' => $row['year'],
                'revenue' => $row['revenue'],
                'net_income' => $row['net_income_attributable'],
                'net_margin_pct' => $this->pct($row['net_income_attributable'], $row['revenue']),
                'roe_end_pct' => $this->pct($row['net_income_attributable'], $row['equity_attributable']),
                'operating_cash_flow' => $row['operating_cash_flow'],
            ];
        }

        return [
            'ticker' => $doc['ticker'],
            'kind' => $doc['kind'],
            'currency' => $doc['currency'],
            'multiplier' => $doc['multiplier'],
            'source_name' => $doc['source_name'],
            'source_url' => $doc['source_url'] ?? null,
            'imported_at' => $doc['imported_at'],
            'latest_year' => $last['year'],
            'years' => $annual,
            'net_margin_pct' => $this->pct($last['net_income_attributable'], $last['revenue']),
            'revenue_growth_pct' => $this->pct($last['revenue'] - $prev['revenue'], $prev['revenue']),
            'roe_end_pct' => $this->pct($last['net_income_attributable'], $last['equity_attributable']),
            'eps' => $last['shares_outstanding'] > 0 ? round(($last['net_income_attributable'] * $multiplier) / $last['shares_outstanding'], 4) : null,
            'income_positive' => $last['net_income_attributable'] > 0,
            'cash_flow_positive' => $last['operating_cash_flow'] > 0,
            'gate' => 'USER_IMPORTED_UNVERIFIED',
            'decision' => 'HOLD',
            'trading_authorized' => false,
        ];
    }
}
