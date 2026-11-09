<?php

namespace App\Services;

use MathPHP\Statistics\Correlation;
use MathPHP\Statistics\Descriptive;

/**
 * Historical fixed-share portfolio comparison using only overlapping closes.
 * Research-only: no asset recommendation, trade authority, or persistent writes.
 */
class PortfolioScenarioService
{
    public function analyze(array $documents, array $weightsPct): array
    {
        if (count($documents) < 2 || count($documents) > 3
            || count($documents) !== count($weightsPct)
            || array_keys($documents) !== array_keys($weightsPct)) {
            throw new \InvalidArgumentException('Pilih dua atau tiga instrumen berbeda dengan bobotnya.');
        }

        $weightSum = 0.0;
        foreach ($weightsPct as $weight) {
            if (!is_numeric($weight) || !is_finite((float) $weight)
                || (float) $weight <= 0 || (float) $weight > 100) {
                throw new \InvalidArgumentException('Setiap instrumen terpilih harus memiliki bobot lebih dari 0% hingga 100%.');
            }
            $weightSum += (float) $weight;
        }
        if (abs($weightSum - 100.0) > 0.00001) {
            throw new \InvalidArgumentException('Jumlah seluruh bobot harus tepat 100%.');
        }

        $series = [];
        $metadata = [];
        foreach ($documents as $ticker => $document) {
            if (!preg_match('/^[A-Z0-9.\-]{1,16}$/', (string) $ticker)
                || !is_array($document['rows'] ?? null)
                || count($document['rows']) < 60 || count($document['rows']) > 10000) {
                throw new \InvalidArgumentException('Riwayat instrumen tidak memenuhi batas panjang/format yang diperlukan.');
            }
            $prices = [];
            foreach ($document['rows'] as $row) {
                $date = (string) ($row['date'] ?? '');
                $close = $row['close'] ?? null;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                    || !is_numeric($close) || !is_finite((float) $close)
                    || (float) $close <= 0 || isset($prices[$date])) {
                    throw new \InvalidArgumentException('Tanggal/harga pada seri '.$ticker.' tidak valid atau berulang.');
                }
                $prices[$date] = (float) $close;
            }
            $series[$ticker] = $prices;
            $metadata[$ticker] = [
                'ticker' => $ticker,
                'weight_pct' => round((float) $weightsPct[$ticker], 2),
                'kind' => (string) ($document['kind'] ?? 'unknown'),
                'source_name' => (string) ($document['source_name'] ?? 'unknown'),
                'imported_at' => (string) ($document['imported_at'] ?? 'unknown'),
            ];
        }

        $common = null;
        foreach ($series as $prices) {
            $common = $common === null ? $prices : array_intersect_key($common, $prices);
        }
        $dates = array_keys($common ?? []);
        sort($dates, SORT_STRING);
        if (count($dates) < 40) {
            throw new \InvalidArgumentException('Minimal 40 tanggal penutupan yang sama diperlukan. Seri memiliki terlalu sedikit sesi beririsan.');
        }

        $quality = (new PortfolioDataQualityService())->audit($documents, $dates);
        if ($quality['currency_mismatch']) {
            throw new \InvalidArgumentException('Mata uang sumber yang dideklarasikan berbeda. Perbandingan dihentikan sampai konversi FX tersedia.');
        }

        $tickers = array_keys($documents);
        $n = count($dates);
        $count = count($tickers);
        $equalWeightsSelected = true;
        foreach ($weightsPct as $weight) {
            if (abs((float) $weight - 100.0 / $count) > 0.00001) {
                $equalWeightsSelected = false;
            }
        }
        $first = $dates[0];
        $last = $dates[$n - 1];
        $portfolio = [];
        $equal = [];
        $individual = [];
        foreach ($tickers as $ticker) {
            $individual[$ticker] = [];
        }
        foreach ($dates as $date) {
            $portfolioValue = 0.0;
            $equalValue = 0.0;
            foreach ($tickers as $ticker) {
                $relative = $series[$ticker][$date] / $series[$ticker][$first];
                if (!is_finite($relative) || $relative <= 0) {
                    throw new \InvalidArgumentException('Skala harga tidak valid untuk perbandingan relatif portofolio.');
                }
                $individual[$ticker][] = $relative;
                $portfolioValue += ((float) $weightsPct[$ticker] / 100.0) * $relative;
                $equalValue += $relative / $count;
            }
            if (!is_finite($portfolioValue) || !is_finite($equalValue)) {
                throw new \InvalidArgumentException('Nilai portofolio tidak dapat dihitung secara numerik.');
            }
            $portfolio[] = $portfolioValue;
            $equal[] = $equalValue;
        }

        foreach ($metadata as $ticker => &$item) {
            $value = $individual[$ticker][$n - 1];
            $item['return_pct'] = round(($value - 1) * 100, 2);
            $item['start_close'] = round($series[$ticker][$first], 4);
            $item['end_close'] = round($series[$ticker][$last], 4);
        }
        unset($item);

        $pairs = [];
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $firstTicker = $tickers[$i];
                $secondTicker = $tickers[$j];
                $a = $this->returns($individual[$firstTicker]);
                $b = $this->returns($individual[$secondTicker]);
                $correlation = $this->correlation($a, $b);
                $pairs[] = [
                    'pair' => $firstTicker.' / '.$secondTicker,
                    'correlation' => $correlation === null ? null : round($correlation, 3),
                    'observations' => count($a),
                ];
            }
        }

        $hhi = 0.0;
        foreach ($weightsPct as $weight) {
            $hhi += pow((float) $weight / 100.0, 2);
        }
        $allSynthetic = true;
        $anySynthetic = false;
        foreach ($metadata as $item) {
            $synthetic = $item['kind'] === 'synthetic';
            $allSynthetic = $allSynthetic && $synthetic;
            $anySynthetic = $anySynthetic || $synthetic;
        }

        $portfolioReturns = $this->returns($portfolio);
        $tailRisk = $this->tailRisk($portfolioReturns);

        $min = min(min($portfolio), min($equal));
        $max = max(max($portfolio), max($equal));
        $range = max(0.0000001, $max - $min);
        $chart = static function (array $values) use ($min, $range, $n): string {
            $points = [];
            foreach ($values as $index => $value) {
                $x = 14 + 652 * $index / max(1, $n - 1);
                $y = 184 - 166 * ($value - $min) / $range;
                $points[] = round($x, 1).','.round($y, 1);
            }
            return implode(' ', $points);
        };

        return [
            'tickers' => $tickers,
            'instruments' => array_values($metadata),
            'common_sessions' => $n,
            'return_observations' => $n - 1,
            'start_date' => $first,
            'end_date' => $last,
            'portfolio_return_pct' => round(($portfolio[$n - 1] - 1) * 100, 2),
            'equal_weight_return_pct' => round(($equal[$n - 1] - 1) * 100, 2),
            'equal_weights_selected' => $equalWeightsSelected,
            'relative_excess_pp' => round(($portfolio[$n - 1] - $equal[$n - 1]) * 100, 2),
            'worst_drawdown_pct' => round($this->maxDrawdown($portfolio) * 100, 2),
            'equal_weight_drawdown_pct' => round($this->maxDrawdown($equal) * 100, 2),
            'session_volatility_pct' => round($this->stdev($portfolioReturns) * 100, 3),
            'historical_var_95_pct' => $tailRisk['var_95_pct'],
            'historical_cvar_95_pct' => $tailRisk['cvar_95_pct'],
            'hhi_10000' => round($hhi * 10000),
            'effective_asset_count' => round(1.0 / $hhi, 2),
            'max_weight_pct' => round(max($weightsPct), 2),
            'pairwise_correlations' => $pairs,
            'chart_portfolio' => $chart($portfolio),
            'chart_equal' => $chart($equal),
            'synthetic_state' => $allSynthetic ? 'ALL_SYNTHETIC' : ($anySynthetic ? 'MIXED_SYNTHETIC_AND_USER' : 'USER_IMPORTED'),
            'quality' => $quality,
            'evidence' => 'USER_IMPORTED_UNVERIFIED',
            'decision' => 'HOLD',
            'trading_authorized' => false,
        ];
    }

    private function returns(array $values): array
    {
        $returns = [];
        for ($i = 1; $i < count($values); $i++) {
            $returns[] = $values[$i] / $values[$i - 1] - 1.0;
        }
        return $returns;
    }

    private function stdev(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }
        return Descriptive::standardDeviation($values);
    }

    private function correlation(array $a, array $b): ?float
    {
        $n = count($a);
        if ($n < 2 || $n !== count($b)) {
            return null;
        }
        try {
            return max(-1.0, min(1.0, Correlation::r($a, $b)));
        } catch (\Throwable) {
            return null;
        }
    }

    private function tailRisk(array $returns): array
    {
        if (count($returns) < 20) {
            return ['var_95_pct' => null, 'cvar_95_pct' => null];
        }
        $cutoff = Descriptive::percentile($returns, 5);
        $tail = array_values(array_filter($returns, static fn (float $value): bool => $value <= $cutoff));
        $cvar = $tail === [] ? $cutoff : array_sum($tail) / count($tail);
        return [
            'var_95_pct' => round(max(0, -100 * $cutoff), 3),
            'cvar_95_pct' => round(max(0, -100 * $cvar), 3),
        ];
    }

    private function maxDrawdown(array $values): float
    {
        $peak = $values[0];
        $worst = 0.0;
        foreach ($values as $value) {
            $peak = max($peak, $value);
            $worst = max($worst, ($peak - $value) / $peak);
        }
        return $worst;
    }
}
