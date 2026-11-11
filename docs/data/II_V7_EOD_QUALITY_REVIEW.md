# II V7 — EOD Source Quality Review

## Product milestone
Expose the latest-date market-data integrity evidence in the existing **Harga Pasar** workspace without changing pricing rights or data authority.

## Reuse-first implementation
`MarketPriceQualityService` is the single user-scoped implementation for latest-date source agreement. Both `FundamentalSnapshotService` (valuation gate) and `FundamentalHubController` (review UI) consume this service. History remains immutable.

## User-visible behavior
- For each ticker with dated observations, compare **all sources on the latest observed date** rather than only the last 30 displayed records.
- Display `N/A_MARKET_PRICE_CONFLICT`, `N/A_FUTURE_MARKET_PRICE_DATE`, or `N/A_SPLIT_ADJUSTMENT_UNALIGNED` when appropriate.
- Display `CONSISTENT_REVIEW_REQUIRED` for aligned same-date raw closes. This is **not** an approval for valuation or trading: share-basis and disclosure rights gates remain separate.
- Reviewers can expand the source provenance, price, currency, adjustment status, usage rights, and source SHA-256.
- The existing latest-30 register is a visible slice, not the complete historical inventory.
- All data is scoped by the authenticated user ID, with browser responses kept private and no upstream scraping.

## Admission and license guards
- Preserve `NOT_ADMITTED`, `HOLD`, and `TRADING_AUTHORITY=NONE`.
- This work does not provide real exchange closing prices or permission to redistribute proprietary market data.
- Conflicting prices remain held, not silently averaged or prioritized.
- There are no database migrations, no external dependencies, and no Python.

## Acceptance
GitHub CI and isolated Windows staging: Laravel test suite, Blade compilation, HTTP health, three existing issuer records and six periods, 1,824 preserved FRED observations, SQLite integrity, conflict/user-scope regression. Merge and deploy only after all evidence is green.
