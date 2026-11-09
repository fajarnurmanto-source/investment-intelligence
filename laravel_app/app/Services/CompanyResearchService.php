<?php

namespace App\Services;

/**
 * Native Laravel replacement for the previous research adapter.
 * The fixtures remain synthetic and deliberately cannot grant authority.
 */
class CompanyResearchService
{
    private const COMPANIES = [
        'DEMO2' => [
            'name' => 'Synthetic Consumer Business',
            'sector' => 'Consumer',
            'thesis' => 'Can net margin remain above 15%?',
            'history' => [
                ['period' => 'FY2023', 'net_margin_pct' => 12.2, 'revenue_index' => 100.0],
                ['period' => 'FY2024', 'net_margin_pct' => 14.7, 'revenue_index' => 110.5],
                ['period' => 'FY2025', 'net_margin_pct' => 18.0, 'revenue_index' => 123.6],
            ],
        ],
        'DEMO3' => [
            'name' => 'Synthetic Industrial Business',
            'sector' => 'Industrial',
            'thesis' => 'Can net margin remain above 15%?',
            'history' => [
                ['period' => 'FY2023', 'net_margin_pct' => 13.1, 'revenue_index' => 100.0],
                ['period' => 'FY2024', 'net_margin_pct' => 11.4, 'revenue_index' => 96.0],
                ['period' => 'FY2025', 'net_margin_pct' => 8.0, 'revenue_index' => 104.2],
            ],
        ],
    ];

    public function universe(): array
    {
        $items = [];
        foreach (self::COMPANIES as $ticker => $company) {
            $items[] = [
                'ticker' => $ticker,
                'name' => $company['name'],
                'sector' => $company['sector'],
                'data_class' => 'SYNTHETIC_FIXTURE',
                'admission' => 'NOT_ADMITTED',
            ];
        }

        return $items;
    }

    public function research(string $ticker): array
    {
        $ticker = strtoupper($ticker);
        abort_unless(isset(self::COMPANIES[$ticker]), 404, 'Ticker excluded from the synthetic universe.');

        return [
            'ticker' => $ticker,
            ...self::COMPANIES[$ticker],
            'metric_definitions' => [
                'net_margin_pct' => 'Synthetic net margin percentage; not reconstructed from audited statements',
                'revenue_index' => 'Synthetic revenue index, base FY2023=100; NOT actual currency revenue',
            ],
            'provenance' => [
                'source_id' => 'SYNTHETIC_FIXTURE_LARAVEL_'.$ticker,
                'source_type' => 'GENERATED_DEMO',
                'point_in_time' => 'NOT_APPLICABLE_SYNTHETIC',
                'rights' => 'SYNTHETIC_ONLY',
                'lineage' => 'In-package Laravel synthetic constant; no canonical database connection',
            ],
            'quality' => [
                'state' => 'COMPLETE_UNVERIFIED',
                'blocking_reasons' => ['No admitted real-source evidence', 'No issuer-level rights adjudication'],
            ],
            'admission' => 'NOT_ADMITTED',
            'authority' => 'NONE',
            'effective_decision' => 'HOLD',
            'valuation_authorized' => false,
            'trading_authorized' => false,
        ];
    }
}
