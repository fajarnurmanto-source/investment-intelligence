# II V13 — IDX Edge PRO Free API pilot (third-party)

## Verified provider contract (24 Nov 2026)
Official provider documentation: https://stock.arjum.com/api-docs
- Base URL: https://stock.arjum.com
- Financial statements endpoint: GET /api/financial-statements/{code}
- HTTP auth header: X-API-Key: YOUR_PRIVATE_KEY
- Vendor advertises a **free 1,000 requests/day** allowance, reset 00:00 WIB, which may change.
- This is a third-party provider, NOT an API operated or endorsed by Bursa Efek Indonesia.
- The public documentation currently does not establish exact financial-response schema, fiscal periods, unit scales, independent accuracy, or redistribution rights. This adapter preserves raw JSON evidence as SCHEMA_REVIEW_REQUIRED and UNRECONCILED; it does not invent normalized financial figures or imply licensed use.
- BEI terms prohibit website crawling and certain commercial data uses without permission. This connector does not crawl the BEI site: https://www.idx.id/id/syarat-penggunaan

## Local setup after an approved merge and deployment
1. Create your own free IDX Edge PRO account and private key: https://stock.arjum.com/
2. Edit D:\Research\INVESTMENT RESEARCH\II\runtime\laravel\.env and add:
   II_IDXEDGE_KEY=YOUR_PRIVATE_KEY
   II_IDXEDGE_TICKERS=BBCA,TLKM,ASII
   II_IDXEDGE_AUTO_SYNC=false
3. Use the canonical II deployment, with the exact expected merge commit, to reload the config cache. Never paste keys into a chat or commit them to Git. Do not rotate APP_KEY or touch SQLite.
4. Open http://127.0.0.1:8810/ii/fundamentals?workspace=activation and use the IDX Edge PRO panel. Download the review JSON for BBCA, TLKM and ASII.
5. Via the canonical Laravel runtime, run: php artisan ii:idxedge:pilot ; or for one ticker: php artisan ii:idxedge:pilot --ticker=BBCA
6. Only after a verified live pilot and rights review, enable weekly acquisition with II_IDXEDGE_AUTO_SYNC=true and redeploy/reload the Laravel config. The existing scheduler runs every Monday 06:35 WIB, maximum three tickers and three requests per run.

## Artifacts and safeguards
- Exact upstream HTTP response bytes stored privately as `ii_idxedge_review/{TICKER}/{SOURCE_RESPONSE_SHA256}.raw`; an adjacent `.json` review manifest stores the digest, private raw file path and integrity status. The persisted raw bytes are SHA-256 reverified before the manifest is written; neither file is committed to Git.
- Duplicate raw files are checksum-verified and not rewritten; the existing manifest is not silently replaced. Each fetch still incurs one provider request. Existing legacy manifest-only archives need separate reconciliation; do not consider them byte-verified retroactively.
- No numeric facts enter II canonical issuer tables, ratios, screeners, positions or trading. NOT_ADMITTED / HOLD / TRADING_AUTHORITY=NONE are maintained.
- Fixed HTTPS provider host, verified TLS, no redirects, no user-defined URLs, bounded response size, no retry on 429.
- Invalid/empty/error JSON, 401/403/404/429, source ticker mismatch and HTML errors fail closed.
- No raw provider response or API key logged.
- Report periods remain UNVERIFIED pending a genuine provider response and official issuer-filing reconciliation; do not claim FY 2024/2025 coverage from a fake CI fixture.

## Acceptance
A. Synthetic Linux + Windows Tinker/PHPUnit testing of endpoint/header, private raw-byte archival integrity, UI exclusion of internal raw bytes and safety invariants. A CI PASS is not a provider-live acceptance.
B. Real API key supplied by the user in runtime only; independently inspect controlled BBCA, TLKM, ASII fetch results and exact native response schema.
C. Reconcile FY2024/FY2025 statement values to official audited issuer filings, document mapping of periods, currencies and scales; only then implement canonical metrics ingestion through existing issuer importer with usage-rights reviews.

Do not merge or deploy without user approval.

## Staged archival and interruption recovery (incremental implementation)
- Before the final `.raw` or `.json` path is exposed, data is written to a same-directory `.pending-{random}` file and re-hashed. The Laravel local-disk `move()` then renames it under a checksum-scoped OS advisory lock.
- A subsequent invocation holding the same checksum lock removes abandoned `.pending-*` files **for that checksum only**. It does not repair legacy manifest-only evidence and does not automatically import financial facts.
- Scope limitation: this is a two-file protocol, not a fully durable database transaction. OS crash/power-loss durability, Windows filesystem rename behavior, cross-host locks and remote object disks require separate acceptance. The canonical pilot is a local disk only.
- Test additions cover archive failure, locking, whitespace-preserved original HTTP JSON, legacy evidence, tampered archive and staged recovery. Running CI at the exact final SHA and local Windows acceptance remain mandatory.
