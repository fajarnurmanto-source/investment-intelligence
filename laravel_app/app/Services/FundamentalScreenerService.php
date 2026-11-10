<?php

namespace App\Services;

class FundamentalScreenerService
{
    public function screen(array $records, array $filters): array
    {
        $rows = [];
        foreach ($records as $record) {
            if (($filters['min_margin'] !== null && (($record['net_margin_pct'] ?? null) === null || $record['net_margin_pct'] < $filters['min_margin']))
                || ($filters['min_growth'] !== null && (($record['revenue_growth_pct'] ?? null) === null || $record['revenue_growth_pct'] < $filters['min_growth']))
                || ($filters['min_roe'] !== null && (($record['roe_end_pct'] ?? null) === null || $record['roe_end_pct'] < $filters['min_roe']))
                || ($filters['cash_positive'] && ! ($record['cash_flow_positive'] ?? false))) {
                continue;
            }
            $record['quality_score'] = $this->score($record);
            $record['screen_reasons'] = $this->reasons($record);
            $rows[] = $record;
        }

        $sort = $filters['sort'];
        usort($rows, static function (array $a, array $b) use ($sort): int {
            $comparison = ($b[$sort] ?? -INF) <=> ($a[$sort] ?? -INF);

            return $comparison !== 0 ? $comparison : strcmp($a['ticker'], $b['ticker']);
        });

        return $rows;
    }

    private function score(array $record): float
    {
        $score = 0.0;
        $score += min(25.0, max(0.0, ((float) ($record['net_margin_pct'] ?? 0)) / 20 * 25));
        $score += min(25.0, max(0.0, ((float) ($record['revenue_growth_pct'] ?? 0)) / 15 * 25));
        $score += min(25.0, max(0.0, ((float) ($record['roe_end_pct'] ?? 0)) / 20 * 25));
        $score += ($record['income_positive'] ?? false) ? 12.5 : 0.0;
        $score += ($record['cash_flow_positive'] ?? false) ? 7.5 : 0.0;
        $score += min(5.0, max(0.0, ((float) ($record['data_quality_score'] ?? 0)) / 100 * 5));

        return round($score, 1);
    }

    private function reasons(array $record): array
    {
        return array_values(array_filter([
            ($record['net_margin_pct'] ?? 0) >= 15 ? 'Margin ≥15%' : null,
            ($record['revenue_growth_pct'] ?? 0) >= 5 ? 'Growth ≥5%' : null,
            ($record['roe_end_pct'] ?? 0) >= 15 ? 'ROE ≥15%' : null,
            ($record['cash_flow_positive'] ?? false) ? 'OCF positif' : null,
            ($record['data_quality_score'] ?? 0) >= 85 ? 'Data lengkap' : null,
        ]));
    }
}
