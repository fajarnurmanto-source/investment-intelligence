# II V6 — Market Price Integrity Gate

## Scope
The local Laravel-only research product imports user-supplied, rights-aware dated EOD prices. This change makes source disagreement and future dates explicit **fail-closed** conditions; it does not acquire, scrape, or license new market data.

## Data contract
1. Manual price entry accepts an exact ISO calendar date (YYYY-MM-DD) and rejects a date later than the current server date. CSV batch ingest already rejects future dates.
2. For each authenticated user and issuer, select the most recent observed date. Compare **all** stored observations for that ticker on that same date across sources.
3. A disagreement on currency, a price rounded to six decimals (the CSV precision), or adjustment basis yields `N/A_MARKET_PRICE_CONFLICT`, with P/E, P/BV and EV/EBITDA null. `UNADJUSTED` and `UNADJUSTED_CLOSE` are treated as equivalent raw-close labels.
4. If a legacy record nevertheless has a future observed date, return `N/A_FUTURE_MARKET_PRICE_DATE` and keep valuation metrics null. An existing future-dated row is never silently corrected or erased.
5. If two sources agree exactly on a latest-date observation, do not create a false price conflict. Preserve share-basis and provenance gates from V4.
6. Scoping is by `user_id`; one user's prices cannot create conflicts for another user's issuer record.

## Existing contract preserved
- No automatic source substitution, no fabricated quotes, no free-unlicensed data connectors.
- No change to SQLite schema, filings, issuer periods, CSV rights controls, audit data, FRED observations, or V5 workspaces.
- Explicit `NOT_ADMITTED / HOLD / TRADING_AUTHORITY=NONE` remains in every research result.
- Raw vs split-adjusted price and share-basis reconciliation from V4 remain mandatory.
- A conflict requires source-level inspection in the V5 **Harga Pasar** workspace and an explicit corrected authoritative data source. Because historical observations are immutable, conflicts remain held unless a subsequent newer, fully reconciled observation replaces them in the latest-date view. The tool does **not** silently pick a preferred vendor.

## Acceptance
- GitHub CI migration, 24 feature/unit tests expected (subject to final CI count) and route verification.
- Test equal-price dual-source, conflicting price, cross-user isolation, manual future-date rejection, and old future-dated record.
- User-run isolated Windows V6 staging from pinned PR commit, then merge/production backup/cutover only after staging acceptance.
