# II V4 — Corporate-action ledger and share-basis reconciliation

**Status:** staging candidate. GitHub merge and localhost production deployment are **NOT** part of this commit.

## Purpose and safety

V3 can ingest rights-cleared, dated raw and split-adjusted EOD prices. Even a *raw, unadjusted* close may be in a post-split share unit different from the closing share count in an earlier fiscal report. V4 therefore fails closed for external prices dated after the fiscal period until the share basis has an explicitly sourced reconciliation.

- Analyst records **immutable** stock splits, reverse splits or bonus issues with effective dates, new:old ratios, source URLs, rights and SHA-256.
- Analyst reviews the **entire interval** from fiscal period end (exclusive) to price date (inclusive), attesting that the event list is complete. No event means factor 1, still requiring an attestation.
- A review records SHA-256 of the event-list snapshot and the cumulative new/old share factor. Any later backdated event in that interval automatically makes the review **STALE**; no valuation until re-reviewed.
- A matching raw price uses adjusted comparable shares = fiscal reported shares × event factor for market cap, EPS, P/E, P/BV and EV/EBITDA. Series marked `split_adjusted` remain held, irrespective of review, because provider-specific price-adjustment basis is not established.
- User scoping applies to events, source review, issuer periods and price observations. Research remains `NOT_ADMITTED`, decisions `HOLD`, trading authority `NONE`.

## Important limitations

The reviewer attests coverage; this is **not independent exchange verification**. An absent corporate action may still be omitted by the analyst. Source rights are separate from accuracy. The V4 ledger does not scrape IDX, infer corporate actions from charts, auto-license EOD feeds or permit redistribution. EPS based on closing shares remains a research proxy, not necessarily the IAS 33 weighted-average EPS.

The raw price must be valid by the existing V2/V3 date, currency, unit and usage-right gates. Embedded legacy prices keep their prior behavior; V4 coverage gates specifically apply to separately sourced market-price observations. This initial vertical slice is a reconciliation foundation, not an end-to-end commercial feed.

## Acceptance before merge/deployment

1. CI and Laravel regression, particularly V2 price-snapshot tests and V3 atomic batch tests.
2. Synthetic 2:1 split: raw post-split price cannot value until a sourced interval review; after the review shares double, price ratios use aligned shares.
3. New backdated event invalidates the earlier review. Split-adjusted price remains held. Duplicate/conflicting action is rejected. Another user cannot access or review the issuer's events.
4. UI/CSV surface share-basis status, factor and source; do not report a valuation as verified just because a user attested.
5. Isolated staging SQLite first. After successful acceptance and explicit backup, merge V4 and deploy with canonical local script. Confirm FRED 1,824 observations, 3 real issuers, 6 fiscal periods, storage rights and localhost-only FrankenPHP.

## Remaining product dependency

Actual market-price data and event feeds must be acquired from an expressly permitted source. No real licensed data or API credentials belong in Git. Historical V3 data and existing research documents are unchanged by this additive migration.
