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
    // BYOK local: zero application-wide vendor credentials and no device sync.
    'connectivity_mode' => 'BYOK_LOCAL',
    'multi_device_sync' => false,
    'shared_market_data_key' => false,
    'marketdata' => [
        'provider' => 'marketdata',
        'byok' => true,
        'local_only' => true,
        'export_allowed' => false,
        'raw_archival_allowed' => false,
        'cloud_transfer_allowed' => false,
    ],
    'fred' => [
        'source_id' => 'FRED',
        'name' => 'Federal Reserve Economic Data',
        'endpoint' => env('II_FRED_CSV_ENDPOINT', 'https://fred.stlouisfed.org/graph/fredgraph.csv?id={series}'),
        'terms_url' => 'https://fred.stlouisfed.org/docs/api/terms_of_use.html',
        'attribution' => 'This product uses FRED data but is not endorsed or certified by the Federal Reserve Bank of St. Louis.',
        // Empty by default: FRED access is not a data-redistribution license.
        // A per-series reviewed receipt with explicit use scope is required
        // before the CSV endpoint may return source-derived numbers.
        'report_rights' => [],
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
