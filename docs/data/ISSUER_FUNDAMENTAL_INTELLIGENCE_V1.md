# Issuer Fundamental Intelligence V1

Status: development feature; production database unchanged until an approved deployment.

## Capability

II accepts issuer disclosures through the authenticated Laravel application as normalized CSV (up to 20 periods), XBRL/XML using an explicit IFRS fact allowlist, or PDF source-document archival without automatic extraction.

Every import creates an immutable document version. A duplicate SHA-256 checksum is rejected for the same user. The source registry is isolated per user and versions changes in license/usage-right metadata rather than rewriting prior provenance. It records issuer code/name, reporting period and date, currency, unit multiplier, consolidation status, publisher/source URL, terms URL, license status, usage rights, redistribution flag, import timestamp, original filename, and checksum. Previous source documents are not overwritten.

The additive migration creates `ii_issuer_sources`, `ii_issuer_documents`, and `ii_issuer_periods`; it does not alter existing production tables or legacy user snapshots.

## Normalization and analysis

Supported facts are revenue, net income attributable to owners, assets, equity attributable to owners, operating cash flow, shares outstanding, total debt, cash and cash equivalents, EBITDA, and optional market price. Financial-statement values are multiplied by the declared unit; shares and market price remain actual units.

The service supports negative reported values, explicit restatement flags, consolidated/non-consolidated status, and nullable facts. It computes P/E, P/BV, EV/EBITDA, ROE, debt/equity, revenue growth, earnings growth, EPS, margin, and OCF only when required inputs and valid denominators exist. Missing calculations remain `N/A`.

All records retain `NOT_ADMITTED · HOLD · TRADING_AUTHORITY=NONE`.

## Real-data verification executed

The integrated feature test ingests two comparative periods from PT Telkom Indonesia (Persero) Tbk's audited Annual Report 2024, published on Telkom's official website. The disclosure states that the consolidated tables are in billions of rupiah and includes the 2024/2023 comparatives.

The isolated import records the source as `public_disclosure`, limits usage to `internal_research`, and sets redistribution to false. It covers revenue, profit attributable to owners, total assets, equity attributable to owners, operating cash flow, shares, debt, cash, and EBITDA. The test database—not the production SQLite database—was used.

Official source: <https://www.telkom.co.id/minio/show/data/lampiran/1745244391951_original_Annual%20Report%20Telkom%202024%20%281%29.pdf>

## Import contract

Download the live template at `/ii/fundamentals/example`. Columns may be omitted except `fiscal_year` (or legacy alias `year`); omitted metrics remain null. `period_end` defaults to December 31 and `restated` defaults to false.

```text
fiscal_year,period_end,restated,revenue,net_income_attributable,total_assets,equity_attributable,operating_cash_flow,shares_outstanding,total_debt,cash_and_equivalents,ebitda,market_price
```

PDF import stores provenance and checksum with `DOCUMENT_ONLY` quality and deliberately does not infer facts. Unsupported XBRL taxonomies must be converted to the documented CSV contract while the original source document is retained as a separate immutable import.

Run the focused verification from `laravel_app`:

```powershell
php artisan test --filter=IssuerFundamentalIntelligenceTest
```

It verifies real-data normalization, provenance, rights, unit scaling, nullable valuation, quality scoring, both UIs and CSV exports, duplicate rejection, XBRL mapping, negative values, and safety gates.
