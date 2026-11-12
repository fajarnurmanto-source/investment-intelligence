# II V9 — Real Source Activation & Evidence Coverage

## Why this milestone
V8 is deployed with rights-aware EOD CSV preview and up to 1,000 rows per import. The current production verification showed 3 fundamental issuers / 6 fiscal periods and 1,824 FRED observations. **Those counts do not prove a commercially licensed IDX market-data feed or an independently verified issuer universe.**

## Canonical real data workflow
1. The user opens the **Aktivasi Data V9** workspace. The service enumerates only this authenticated user's actual imported fundamental documents, normalized fiscal periods, and dated EOD prices. A price-only ticker also appears with `DOCUMENT_REQUIRED`.
2. The user follows the verified public official portals below to identify filings or market-data license terms. **Do not scrape, crawl, or automate collection from IDX websites**.
3. The user downloads eligible filings through a permitted workflow, checks its legal terms, and uploads the original PDF or supported XBRL/normalized CSV through the existing **Sumber & Batch ingestion** workspace (1–20 issuer files per manifest).
4. PDF is an immutable provenance archive, **not** numerical extraction. Unsupported XBRL remains on HOLD; normalized CSV and supported XBRL require reconciliation.
5. Market-price EOD is accepted only when rights for the intended research/non-display processing are confirmed and documented. V8 CSV preview stays read-only; approved batches remain atomic. A vendor claim in a form is **not proof of an executed commercial data license**.
6. The activation table and safe CSV export identify the next evidence gap without guessing missing prices or fundamentals.

## Official references (verified Nov 13, 2026)
- Issuer financial and annual reports: https://www.idx.co.id/id/perusahaan-tercatat/laporan-keuangan-dan-tahunan/
- IDX taxonomy/XBRL: https://www.idx.co.id/id/perusahaan-tercatat/xbrl
- Licensed IDX market data (EOD/Historical): https://data.idx.co.id/
- IDX website Terms of Use: https://www.idx.co.id/id/syarat-penggunaan/
- The IDX public site terms restrict scraping/crawling and prohibit commercial usage/redistribution without necessary permission. Availability on the public website must not be treated as permission to commercialize a feed.

## Data contract
- `IssuerCoverageService::report(userId, currentSnapshotRecords, userPriceQuality)` is user-scoped; no remote APIs, changes to production data, schema migrations or new dependencies.
- Reports issuer counts **in this account's imported evidence**, not the universe of all IDX listings.
- Report flags: `DOCUMENT_REQUIRED`, `NUMERIC_EXTRACTION_REQUIRED`, `RIGHTS_REVIEW_REQUIRED`, `NORMALIZATION_REVIEW_REQUIRED`, `SOURCE_ATTESTATION_REQUIRED`, `NUMERIC_RECONCILIATION_REQUIRED`, `LICENSED_PRICE_REQUIRED`, `PRICE_CONFLICT_REVIEW_REQUIRED`, `PRICE_DATE_REVIEW_REQUIRED`, `PRICE_ADJUSTMENT_REVIEW_REQUIRED`, `VALUATION_REVIEW_REQUIRED`, or `RESEARCH_READY_HOLD`. This is an **operational task priority** not an approval or validation authority.
- "Official" and "verified_against_source" metadata are input attestations by the user unless independently reviewed; they must never be rebranded as verified by IDX/II.
- Excel/CSV formula injection is escaped by the existing `csvCell`; coverage CSV is private and no-store.
- Every result remains `NOT_ADMITTED/HOLD/TRADING_AUTHORITY=NONE`.

## CI and Windows acceptance
- Existing full Laravel CI plus new V9 tests for one user's live normalized data, PDF document-only, unknown rights, price-only, inter-user isolation, CSV escaping, and empty coverage.
- Isolated Windows staging snapshot against pinned V9 PR commit. Verify 1,824 FRED, >=3 issuers, >=6 periods, 2 V4 tables and SQLite integrity, then Blade compilation and HTTP smoke. Merge & deploy only after both green.
