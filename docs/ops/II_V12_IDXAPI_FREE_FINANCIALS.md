# II V12 — Zero-cost IDX fundamentals connector (third-party API)

## Why this provider
- **idxapi.io** documents a Free tier of **1,000 metered requests per month**, up to **5 requests per second**, and up to two free keys as checked on 24 Nov 2026: https://idxapi.io/pricing
- Docs: https://idxapi.io/docs. Financial statement endpoints are `GET /v1/income-statement`, `GET /v1/balance-sheet-statement`, and `GET /v1/cash-flow-statement`.
- **Not an IDX/BEI-operated API.** This is a third-party data feed. Respect https://idxapi.io/terms and underlying data rights. No redistribution, resale, or public multi-tenant access without the required permissions. IDX's own website terms forbid crawling: https://www.idx.co.id/id/syarat-penggunaan/.
- The free limit is controlled by the provider and can change.

## Configure (after PR merge and approved V12 deployment)
1. Create your own **Free** account at https://idxapi.io/pricing and generate a key.
2. On the local II machine, store the key **only** in `D:\Research\INVESTMENT RESEARCH\II\runtime\laravel\.env` (not in Git, chat, screenshots, scripts, or browser):
   `II_IDXAPI_KEY=your_free_bearer_key`.
3. For optional weekly *fully automatic* acquisition, add:
   `II_IDXAPI_AUTO_SYNC=true`
   `II_IDXAPI_TICKERS=BBCA,TLKM,ASII`
   Leave `II_IDXAPI_AUTO_SYNC=false` to use only the on-demand browser button.
4. Use the existing canonical II production deployment/configuration procedure to reload the Laravel config cache. **Do not rotate APP_KEY, rewrite DB_DATABASE, or reset production.** Do not hand-edit a running config cache. This feature requires the PHP vendor and FrankenPHP CA trust already stabilized in V11.
5. Navigate to `http://127.0.0.1:8810/ii/fundamentals?workspace=activation` and select **IDXAPI (third-party Free)**. Enter ticker without `.JK` (e.g. `BBCA`) and FY (e.g. 2025); click **Ambil laba rugi + neraca + arus kas**. The browser downloads a JSON review package automatically fetched by Laravel from three real HTTPS API endpoints.

## Fully automated acquisition
- Artisan command: `php artisan ii:idxapi:sync --ticker=BBCA --year=2025`. It must be invoked in the canonical runtime environment; it does **not** need or use Tinker.
- When `II_IDXAPI_AUTO_SYNC=true` and `II_IDXAPI_TICKERS` is configured, the existing Laravel scheduler triggers the command **each Monday at 06:25 Asia/Jakarta** (only when the II scheduler is running).
- Maximum 10 tickers/run; 3 API calls each; roughly **120 requests per 4-week month** for 10 tickers. Provider counts only successful calls toward its free quota; other requests may still be subject to rate limits. The service never retries 429 automatically.
- Private, SHA-256-keyed JSON review artifacts are stored under Laravel's **local private disk** at `ii_idxapi_review/FY<year>/<ticker>-<sha256>.json`. Identical source responses are not rewritten. No remote URL is provided by users: the host is pinned to `https://api.idxapi.io`.
- The `--year` default is the **previous calendar year**. This is a fixed FY snapshot; a separate filing availability and quarterly release strategy is future work.

## Data integrity and scope
- Three HTTPS calls pull IDX-labelled original line items, filing identity, reported currency, scale, and source SHA-256 into a read-only review bundle.
- Responses with wrong ticker/year, multiple periods, inconsistent currency or scale, missing accession, HTML/error responses, invalid numeric shape, or invalid TLS fail closed.
- No attempt is made to infer a fiscal period-end date, missing metric, or normalized financial line-item mapping: **raw native line items remain source data for review**.
- Status `REVIEW_REQUIRED / UNRECONCILED / NOT_ADMITTED / HOLD / TRADING_AUTHORITY=NONE`.
- The connector **does not yet import canonical financial metrics into II's screener**. Doing so safely requires a verified IDX taxonomy/label dictionary, source reconciliation, and compatible legal use rights.
- No automatic database writes, no user account modification, no market-price sourcing, no Python, no scraping of BEI sites.

## Testing and acceptance
- `tools/testing/Invoke-II-AutomatedTests.ps1` (PHPUnit) tests fixed HTTP destinations, schema, rights boundary, auth, quota handling and scheduled review archival using **faked** JSON responses.
- Linux and Windows GitHub CI must pass.
- **Real provider acceptance is NOT proven until a user has created a Free key** and the Laravel web SAPI successfully fetches one real audited ticker/year. Do not claim real-data availability based solely on mock CI.
