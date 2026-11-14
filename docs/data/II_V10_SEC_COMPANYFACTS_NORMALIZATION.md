# II V10 — SEC EDGAR Companyfacts Offline Normalization

## Product result

Offer an **actual-data acquisition pathway** beyond the three issuer records established in V9. SEC EDGAR publishes JSON XBRL companyfacts free of API-key registration. This milestone uses an explicit user-uploaded `companyfacts.json` from that official API and converts supported **US-GAAP annual 10-K** facts into the **existing II fundamental CSV schema**. It is not a second analytics engine, market-price connector, or change to the existing issuer import records.

Official API documentation: https://www.sec.gov/search-filings/edgar-application-programming-interfaces
Official data URL example shape: https://data.sec.gov/api/xbrl/companyfacts/CIK0000320193.json
SEC fair access: https://www.sec.gov/about/webmaster-frequently-asked-questions

## Workflow
1. Choose the **Aktivasi Data V9** tab, scroll to the V10 SEC normalization panel.
2. Identify the company's actual SEC CIK and access its official `companyfacts` JSON using a lawful download method; comply with SEC website usage policy and declare an identifiable User-Agent if using automated downloads. Do **not** label the sample CIK as a confirmed issuer without checking the current SEC response.
3. Upload the original SEC JSON (max 20 MB). The backend does not call SEC, or any external network, and does not store this raw JSON.
4. The backend returns `II_SEC_COMPANYFACTS_CIK##########_REVIEW.csv` with supported annual financial metrics. A 64-character SHA256 of the source JSON is returned in the private response header for provenance.
5. Review per-field mappings, periods, accession consistency, units, restatements, accounting differences and licensing. The downloaded CSV is **not** independently reconciled.
6. After review, reupload the normalized CSV through the existing fundamental source importer with the correct ticker, issuer identity, report date, original official source URL, legal usage rights and **user_attested / unreconciled** initially. Only the normal issuer importer may create durable records.
7. Share basis, market price, conflicts, source-quality review, risk decisions and `NOT_ADMITTED/HOLD/TRADING_AUTHORITY=NONE` remain unchanged.

## Deterministic safety / accuracy rules
- Only `facts.us-gaap`, currency USD (shares measure where appropriate), annual duration 330–400 days, 10-K or 10-K/A, `fp=FY`, valid accession number and a filing date not in the future.
- Fact groups are keyed by **period end + accession number**, never composed from separate filings to fill gaps. The most recent filing for the last date of a calendar end-year wins. Maximum five years.
- Tags: `RevenueFromContractWithCustomerExcludingAssessedTax`, `Revenues`, `SalesRevenueNet` (revenue); `NetIncomeLoss`; `Assets`; `StockholdersEquityIncludingPortionAttributableToNoncontrollingInterest`; `StockholdersEquity`; `NetCashProvidedByUsedInOperatingActivities`; `CommonStockSharesOutstanding`; `CashAndCashEquivalentsAtCarryingValue`.
- No raw field-to-field synthesis or arbitrary inference. `total_debt`, `ebitda`, `market_price` and `market_price_as_of` remain null, not zero. Equivalent tags and consolidated-vs-parent subtleties require reviewer reconciliation. Generated `restated=false` is merely default and not a verified no-restatement conclusion.
- Source JSON's company `entityName` is untrusted, and the CIK does not automatically determine the stock ticker.
- No API keys, scraping, cron tasks, external fetches, new packages, migrations or Python. The form and route are authenticated, rate-limited, and the response is private/no-store and CSV-safe.
- Public availability of US SEC facts is **not** a blanket license for repackaging, reselling, or representing SEC-verified analytics.

## Admission checklist

Run CI, staging V10 isolated from V9 production, Blade compilation, owner-only HTTP, SQLite counts/health, and verify no writes from normalization alone. Verify actual source data separately through user upload rather than fixture tests; do not count synthetic fixtures as production coverage.

V9 production baseline: `1235edd8ab0d352106e2008350998e4a8e9d9cff`. No production changes until Windows staging passes and PR is merged.
