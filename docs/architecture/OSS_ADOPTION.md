# OSS adoption record

| Package | Version | License | Compatibility | Integrated use |
|---|---:|---|---|---|
| `laravel/framework` | 13.x | MIT | PHP ≥8.3 | Sole active backend runtime, HTTP, queue, scheduler, cache, events, Eloquent, auth |
| `league/csv` | 9.29.0 | MIT | PHP ≥8.2; compatible with II PHP 8.5 | Streaming FRED ingestion and safe CSV reports/exports |
| `markrogoyski/math-php` | 2.12.0 | MIT | PHP ≥8.1; compatible with II PHP 8.5 | Standard deviation, Pearson correlation, percentile VaR/CVaR analytics |

Primary references:

- https://github.com/thephpleague/csv
- https://github.com/markrogoyski/math-php

The packages add no paid service, telemetry, broker connection, or proprietary data. Composer lock pins the resolved versions for reproducibility.
