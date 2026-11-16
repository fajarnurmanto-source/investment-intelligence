# II V11 SEC HTTPS CA Trust — permanent FrankenPHP Windows control

**Root cause (Windows 16 Nov 2026):** V11 browser HTTP failed on legitimate SEC endpoint with `Illuminate\\Http\\Client\\ConnectionException`, `cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)`. Windows PHP CLI fetched and normalized 3,859,564 bytes under 15 seconds while the managed FrankenPHP web SAPI did not have a usable trust-store path.

## Change
The tracked `deploy/php.ini` declares:
- `curl.cainfo = "${II_RUNTIME_ROOT}/certs/cacert.pem"`
- `openssl.cafile = "${II_RUNTIME_ROOT}/certs/cacert.pem"`

The existing canonical `start-ii.ps1` already calls `Set-IIProductionEnvironment`, which exports `II_RUNTIME_ROOT` before spawning FrankenPHP. The php.ini uses the existing absolute runtime path; no app code, migrations, Laravel session, database, or APP_KEY changes.

The trusted certificate bundle is **not** committed into source. Provision `runtime/laravel/certs/cacert.pem` via the official [curl.se CA extraction](https://curl.se/docs/caextract.html) over Windows TLS, separately fetch `https://curl.se/ca/cacert.pem.sha256`, validate matching SHA256 and >70 PEM certificate blocks before acceptance. The CA source is Mozilla CA store, MPL 2.0.

## Release process
1. Run `tools\production\Install-IICaBundle.ps1`. It downloads only the fixed curl.se PEM and checksum URLs, verifies SHA-256, size and certificate count, then atomically installs the runtime file and metadata. `Deploy-IIProduction.ps1` runs this automatically before stopping production.
2. Run the integrated application tests and an isolated FrankenPHP web-SAPI acceptance with `ini_get('curl.cainfo')`, `ini_get('openssl.cafile')`, one bounded SEC GET, TLS verification **enabled** and no redirects; require HTTP 200, valid `companyfacts`, expected CIK and nonempty body.
3. After CI and Windows acceptance PASS, merge PR with normal two-parent merge.
4. Deployment: use `Deploy-IIProduction.ps1 -ExpectedCommit <merge-sha>`. It refuses dirty/mismatched source, creates a consistent SQLite + environment + storage + CA recovery point, preserves APP_KEY, stops managed processes before Composer/cache/migration work, and restarts via the official launcher. Verify `/up`, DB integrity, account session continuity, and a real browser SEC fetch.

**Never** set `verify=false`, `CURLOPT_SSL_VERIFYPEER=0`, `CURLOPT_SSL_VERIFYHOST=0`, or untrusted proxy-root workarounds. No secrets or downloaded SEC JSON should be stored by acceptance.

## Permanent application-level controls (not repeatable one-off fixes)

- Managed `start-ii.ps1` reports `II_SEC_CA_BUNDLE=READY` or a specific warning each start; keeps offline research usable even if external SEC is unavailable.
- `SecOfficialCompanyFactsService::tlsOptions()` explicitly pins Guzzle's `verify` option to the canonical runtime CA bundle when running under FrankenPHP on Windows. The service fails closed with `SEC_TLS_CA_MISSING` or `SEC_TLS_CA_INVALID`, rather than hiding the TLS root cause as generic downtime. Neither disables certificate or hostname verification.
- `php artisan ii:doctor --email=<local account>` reports SQLite integrity, whether this exact account exists, its research role, **account-owned** documents and periods (period ownership is derived through its parent issuer document), global document count for the local operator, and runtime CA readiness without modifying data. `--strict` returns nonzero on unmet prerequisites. The doctor does not request SEC or reveal a password.
- `php artisan ii:user status|create|reset-password|set-role --email=<local account> --role=analyst|reviewer|admin` is the canonical account operation. Passwords use hidden double entry; reset invalidates that user's database sessions while retaining the same user ID and document/case ownership. Existing accounts are never overwritten by `create`.
- Product dashboard metrics, research-case lists, details, notes and exports are constrained to the authenticated owner. Cross-account identifiers deliberately return 404.
- Login copy now correctly identifies V11 (not hardcoded VS6). The Fundamental activation workspace explains that totals are **per logged-in account** and normalized SEC CSV is not imported automatically. Do not merge accounts or reassign data without verified authority.
- Regression tests include live-service TLS configuration boundary (mocked), user-vs-database counts, and all existing V11 SEC access tests. Only isolated Windows FrankenPHP HTTPS acceptance establishes actual trust store interoperability.
- Reuse policy: Laravel session authentication, Eloquent, Illuminate HTTP Client/Guzzle, and Composer were already present. `composer/ca-bundle` was evaluated as an established MIT-licensed alternative, but adding a second CA distribution and altering `composer.lock` is unnecessary when an officially sourced Mozilla PEM, explicit Guzzle `verify` path and managed launcher fix the observed web-SAPI error. Keep runtime CA renewal in standard operating maintenance, not a new data acquisition engine.
