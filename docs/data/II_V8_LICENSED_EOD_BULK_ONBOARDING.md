# II V8 — Licensed EOD Bulk Data Onboarding

## Objective

Move beyond the 100-row EOD CSV cap to a meaningful, rights-aware ingest workflow capable of handling **1,000 closing-price observations per batch**. This is an importer readiness milestone, **not** proof that production holds 1,000 real licensed observations.

## Current capabilities

- The existing user-uploaded CSV schema is unchanged: `ticker,observed_on,currency,price,adjustment_status`.
- Explicit max 2 MB and max 1,000 rows are enforced at controller and service levels.
- An authenticated, rate-limited **preview-only** endpoint examines exactly the same CSV validation as the actual importer, but writes nothing to the application database or private file storage.
- The preview shows parsed row count, unique tickers, earliest/latest date, truncated SHA-256, whether the identical batch was previously imported by this user, historical same-ticker/date overlaps, and **potential conflicts** with existing prices (currency/6-decimal value/raw-vs-adjusted basis).
- A reviewer must reselect the file for real import, supply named provenance and attest appropriate usage rights. All imports remain atomic, with immutable private source CSV and per-observation SHA-256.
- Preview does not approve a vendor, waive rights restrictions, or override V4/V6/V7 valuation gates. A price conflict remains `N/A_MARKET_PRICE_CONFLICT`; all investment outputs remain `NOT_ADMITTED/HOLD/TRADING_AUTHORITY=NONE`.

## Important distinctions

- Preview conflict checks are **potential** disagreements with existing stored observations on the same ticker/date. It is **not** a reconciliation or override.
- A valid and internally consistent CSV may still contain inaccurate or unauthorized data; provenance and license confirmations remain mandatory at actual import.
- Real exchange prices must be obtained via a provider or disclosure with suitable lawful use rights; no exchange scraping, invented prices, or public web redistribution is introduced.
- The max batch size remains 1,000 to protect local laptop/SQLite resources. Large datasets should be split into separately attested sources/batches; overlapping dates remain visible in quality review.

## Acceptance criteria

CI regression tests for read-only preflight, cross-user isolation, 125-row real flow (beyond old 100-row limit), over-1,000 rejection without partial writes, and authorization. Windows staging from pinned commit with own SQLite snapshot, full Laravel tests, Blade compilation and HTTP smoke. Do not merge or deploy before both pass.

## Production data boundaries

No schema migration and no alteration to previously imported prices, 1,824 FRED observations, 3 issuers, 6 fiscal periods, existing source-rights metadata or user permissions.
