# II V11 — Official SEC On-Demand API Intake

## Product objective
V10 can normalize **manually uploaded** US-GAAP SEC companyfacts JSON. V11 removes the manual JSON download step for one user-requested SEC CIK while retaining V10's exact normalization logic, the review CSV handoff, and the existing human-attested issuer import path.

**Source of truth:** SEC [EDGAR data API](https://www.sec.gov/search-filings/edgar-application-programming-interfaces). The Company Facts endpoint is accessible without API key at https://data.sec.gov/api/xbrl/companyfacts/CIK##########.json. This is not the authenticated EDGAR filing API.

**Fair access:** SEC [developer FAQ](https://www.sec.gov/about/webmaster-frequently-asked-questions) and [access guidance](https://www.sec.gov/search-filings/edgar-search-assistance/accessing-edgar-data) expect declared `User-Agent`, representative contact email and non-excessive requests (official ceiling 10/second). This implementation uses at most **two requests/minute for this form**, does not crawl or schedule extraction, and sends the email entered/attested by the user to SEC in the User-Agent header.

## Workflow
1. In **Aktivasi Data**, enter a real CIK (1–10 digits), a reachable contact email the user is authorized to submit and confirm this address may be transmitted to SEC.
2. Server invokes one HTTPS GET against the fixed SEC hostname and exact Company Facts CIK endpoint, with explicitly declared `User-Agent`, 5-second connection timeout, 15-second overall timeout, TLS validation and **no redirects**.
3. Non-200, 403/429, 404, non-JSON, >20 MB and CIK mismatch **fail closed** with a visible message; no automatic retry and no data persistence. Large organizations with >20MB JSON should use a separately assessed acquisition workflow.
4. Exactly the same V10 `convertRaw` implementation produces a normalized annual 10-K US-GAAP CSV with source SHA-256, restricted to supported tags/units/accessions.
5. The private CSV download is labelled `SEC_OFFICIAL_API` and `X-II-Review-Required: true`, not independently reconciled, licensed for commercialization, or approved to trade.
6. The user reviews and imports the CSV through the existing /ii/fundamentals/import path, with true ticker and original source citation. II does not automatically infer ticker from CIK or create false quarterly records.
7. Every result remains `NOT_ADMITTED/HOLD/TRADING_AUTHORITY=NONE`.

## Security and architecture
- Scope: one authenticated, manually initiated, throttled POST. No submitted URLs, URL construction from arbitrary hosts, redirects, background scanning, market-price scraping, user-agent spoofing by default, extra packages, migrations or Python runtime.
- Email is entered by the user and sent to SEC, **not written** by this action to issuer DB or disk. The companyfacts source JSON and normalized CSV are not retained by this endpoint.
- The read-only presentation and normalization is verified with `Http::fake`; **tests do not prove actual SEC availability, independent numeric validity, commercial rights, or that additional real issuer records were created**.
- Preserve V10's 3 issuers, 6 periods, 1,824 FRED observations as initial **production** baseline; do not increase counts by counting fixtures.

## Release gate
V10 deployed production commit `5d7f25c448174f21c51ec1beb348e328ec0cb9e8`. Run GitHub CI, Windows isolated staging with preserved SQLite, HTTP smoke and full Laravel regression, then merge and separately execute guarded deployment. No production changes from this PR alone.


## V7 BYOK-local provenance extension — 25 November 2026

- SEC official on-demand API and user-uploaded Companyfacts JSON now generate a **local-review-only CSV**, each with separate source-origin status. A source digest and exact CSV digest are recorded in the owner-scoped lineage registry; the source→normalization transform is registered in one SQLite transaction. Raw SEC JSON, financial values, contact email and CSV bytes are not copied into registry metadata.
- The response adds `X-II-Lineage-Node`, `X-II-Content-SHA256`, `X-II-Lineage-Status`, and `X-II-Export-Scope: LOCAL_REVIEW_ONLY_NOT_CLEARED_FOR_REDISTRIBUTION`. Existing `X-II-Source-Sha256` and `X-II-Review-Required: true` continue.
- Direct official API origin **does not** mean user-uploaded JSON is authenticated by SEC; both require human numeric reconciliation. The CSV is for an existing user-initiated review workflow and never confers a general license for commercial distribution, bulk exports or cloud LLM transfers.
- Existing code paths still avoid permanent raw SEC JSON/financial persistence during this review step. The new **metadata-only registry writes are intentional** and should not be mistaken for admitted/reconciled financial records.
