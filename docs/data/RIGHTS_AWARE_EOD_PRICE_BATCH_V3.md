# Investment Intelligence V3 — Rights-aware EOD price batch intake

**Status:** feature candidate, 11 November 2026. **Production:** unchanged until local acceptance, verified backup, and controlled deployment.

## Reason for this adapter

The official Indonesia Stock Exchange [Terms of Use](https://www.idx.co.id/id/syarat-penggunaan) prohibit website scraping/crawling and commercial re-use/redistribution without appropriate written permissions. IDX [Data Services](https://data.idx.co.id/) offers licensed market data. Public access to a price page is not a commercial data license.

Therefore V3 first accepts **user-supplied, rights-cleared CSV closing prices**, not an undocumented IDX API, unauthorized scraper, guessed price, paid dependency, or price embedded in issuer fundamentals.

## Flow

1. Analyst obtains a permitted source file outside II and confirms its terms.
2. Upload 1–100 rows of CSV (max 1 MiB), with a shared publisher/source URL, rights classification, and provenance confidence.
3. The importer validates all rows, prevents duplicate ticker/date within a single file, rejects future dates, and stores an immutable private original CSV under a SHA-256-derived path.
4. Existing `IssuerMarketPriceService` validates and stores individual immutable observations in a single database transaction. A duplicate or invalid row rolls back **all** inserted observations and removes the staged file.
5. `batch_sha256` connects each observation to the private source file. Existing V2 snapshot and screener services consume the observations without rebuilding their valuation engine.
6. `split_adjusted` rows remain `N/A_SPLIT_ADJUSTMENT_UNALIGNED` until corresponding share-count adjustment basis is provided; no trading authorization is introduced.

## CSV contract

UTF-8, comma-delimited, exactly five columns:

```csv
ticker,observed_on,currency,price,adjustment_status
DEMO1.JK,2025-01-02,IDR,1000.50,unadjusted_close
DEMO2.JK,2025-01-02,IDR,250,split_adjusted
```

These values are *synthetic*, not actual market observations. Allowed currencies: IDR, USD. Price: positive decimal with up to six decimal places. Dates: YYYY-MM-DD, not future-dated. Adjustment status: `unadjusted`, `unadjusted_close`, or `split_adjusted`.

## Admission and rights

- Default: `EVIDENCE=NOT_ADMITTED`, `DECISION=HOLD`, `TRADING_AUTHORITY=NONE`.
- `internal_research` or `licensed_internal` is not redistribution permission; no public dataset or report may be republished by II from these sources.
- A price may be displayed for internal analysis only if dated, currency/unit aligned with the issuer snapshot, and its rights accepted by the user.
- A price may be stored even if unusable for valuation, preserving the source and reason for `N/A`.
- No private CSV, credentials, `.env`, or original licensed data belong in Git.

## Local acceptance before release

- PHPUnit `MarketPriceBatchV3Test` and V2 regression suite.
- Verify a valid synthetic 3-row batch and per-record SHA/file linkage.
- Verify duplicates, invalid dates, formula-like values, and missing rights fail without partial writes.
- Verify immutable original file is stored privately and inaccessible to other users.
- Verify preexisting 1,824 FRED observations, TLKM/ASII/UNVR fundamentals and localhost-only FrankenPHP remain unchanged.
- Await rights-cleared real-price data from the operator. No licensed live IDX integration is implied by this candidate.

## Subsequent milestones

- Rights-cleared EOD data acceptance from an explicitly licensed provider/source, followed by report-price date alignment.
- Traded share-count/corporate-action reconciliation for adjusted series.
- Production-scale cross-issuer time-series selection, valuation comparative panels, data freshness policies and license-aware distribution.
