# FRED real-data connector

Pipeline: `FRED CSV → Laravel HTTP Client → League CSV normalization → Eloquent observations → MathPHP analytics → Macro dashboard → CSV report`.

Allow-listed series: `CPIAUCSL` and `FEDFUNDS`. Each record stores source, series, date, units, frequency, retrieval timestamp, ingestion-run UUID, source URL, response SHA-256, and quality status. Runs record success/failure, counts, missing observations, and bounded failure text. Failed runs do not delete or overwrite the last valid dataset.

The connector is for internal research and displays the required non-endorsement notice and Terms link. FRED notes that some series may be owned by third parties; every additional series must receive a source-owner and rights review before entering the allow-list. No paid service or API key is activated.

- Terms: https://fred.stlouisfed.org/docs/api/terms_of_use.html
- Observations documentation: https://fred.stlouisfed.org/docs/api/fred/series_observations.html
