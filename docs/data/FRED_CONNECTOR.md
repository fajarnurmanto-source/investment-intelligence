# FRED real-data connector

Pipeline: `FRED CSV → Laravel HTTP Client → League CSV normalization → Eloquent observations → MathPHP analytics → Macro dashboard → CSV report`.

Allow-listed series: `CPIAUCSL` and `FEDFUNDS`. Each record stores source, series, date, units, frequency, retrieval timestamp, ingestion-run UUID, source URL, response SHA-256, and quality status. Runs record success/failure, counts, missing observations, and bounded failure text. Failed runs do not delete or overwrite the last valid dataset.

The connector is for internal research and displays the required non-endorsement notice and Terms link. FRED notes that some series may be owned by third parties; every additional series must receive a source-owner and rights review before entering the allow-list. No paid service or API key is activated.

- Terms: https://fred.stlouisfed.org/docs/api/terms_of_use.html
- Observations documentation: https://fred.stlouisfed.org/docs/api/fred/series_observations.html

## V6 local BYOK rights cutover — 25 November 2026

- The on-device **macro dashboard continues to show locally stored observations** for internal research; the CSV report is now **default denied** until an independent rights review is recorded for **every** included series. FRED connectivity and publicly accessible data do not themselves grant redistribution or commercial resale rights.
- Authenticated `GET /ii/macro/report/rights` returns a numeric-free review manifest with FRED source ID, original data owner, response SHA-256, status, and denial reasons. Preflight checks trusted HTTPS FRED host, allow-listed source/series, valid latest observation, successful ingestion receipt, and matching source checksum.
- `config('ii.fred.report_rights.<SERIES_ID>')` is empty by default. Any future exception requires `status=REVIEWED_ALLOWED`, `scope=LOCAL_PERSONAL_NONCOMMERCIAL_CSV`, the exact original source owner, and a local `receipt_file` placed under the private `local` storage disk's `ii-rights-reviews/` directory. The file must physically exist, be no larger than 2 MB, and SHA-256-match `receipt_sha256` before any CSV is released. **Do not ship fabricated review receipts or promote CI fixtures to production.** No configuration here grants cloud LLM transfer or commercial redistribution.
- When a specific use is cleared, `MacroReportLineageService` creates a metadata-only FRED source→CSV render graph, verifies it before responding, and returns content SHA-256 and report node headers. These records are an auditable local claim, **not a signed license or an externally tamper-proof ledger**.
- CI synthetic approvals prove the control path works; they are not legal permission. The current release remains a draft until legal rights, Windows installation, and runtime acceptance are completed.
