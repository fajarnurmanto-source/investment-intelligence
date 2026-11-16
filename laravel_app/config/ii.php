<?php

return [
    // Single UI release label, no scattered stale VS6-style strings.
    'release' => env('II_PRODUCT_RELEASE', 'V11'),
    'official_connectors' => [
        'sec_companyfacts' => [
            'host' => 'data.sec.gov',
            'rate_limit_per_minute' => 2,
            'usage' => 'user-initiated internal research review',
            'redistribution' => false,
        ],
    ],
    'fred' => [
        'source_id' => 'FRED',
        'name' => 'Federal Reserve Economic Data',
        'endpoint' => env('II_FRED_CSV_ENDPOINT', 'https://fred.stlouisfed.org/graph/fredgraph.csv?id={series}'),
        'terms_url' => 'https://fred.stlouisfed.org/docs/api/terms_of_use.html',
        'attribution' => 'This product uses FRED data but is not endorsed or certified by the Federal Reserve Bank of St. Louis.',
        'series' => [
            'CPIAUCSL' => [
                'title' => 'Consumer Price Index for All Urban Consumers',
                'units' => 'Index 1982-1984=100',
                'frequency' => 'Monthly',
                'owner' => 'U.S. Bureau of Labor Statistics',
            ],
            'FEDFUNDS' => [
                'title' => 'Federal Funds Effective Rate',
                'units' => 'Percent',
                'frequency' => 'Monthly',
                'owner' => 'Board of Governors of the Federal Reserve System',
            ],
        ],
    ],
];
