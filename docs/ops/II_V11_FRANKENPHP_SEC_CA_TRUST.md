# II V11 SEC HTTPS CA Trust — FrankenPHP Windows hotfix

**Root cause (Windows 16 Nov 2026):** V11 browser HTTP failed on legitimate SEC endpoint with `Illuminate\\Http\\Client\\ConnectionException`, `cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)`. Windows PHP CLI fetched and normalized 3,859,564 bytes under 15 seconds while the managed FrankenPHP web SAPI did not have a usable trust-store path.

## Change
The tracked `deploy/php.ini` declares:
- `curl.cainfo = "${II_RUNTIME_ROOT}/certs/cacert.pem"`
- `openssl.cafile = "${II_RUNTIME_ROOT}/certs/cacert.pem"`

The existing canonical `start-ii.ps1` already calls `Set-IIProductionEnvironment`, which exports `II_RUNTIME_ROOT` before spawning FrankenPHP. The php.ini uses the existing absolute runtime path; no app code, migrations, Laravel session, database, or APP_KEY changes.

The trusted certificate bundle is **not** committed into source. Provision `runtime/laravel/certs/cacert.pem` via the official [curl.se CA extraction](https://curl.se/docs/caextract.html) over Windows TLS, separately fetch `https://curl.se/ca/cacert.pem.sha256`, validate matching SHA256 and >70 PEM certificate blocks before acceptance. The CA source is Mozilla CA store, MPL 2.0.

## Release process
1. Pin local production to `a4303e9b97cbe35f0d9cd8569ca577ffc25153b0`.
2. Provision trusted bundle to the **runtime** path (does not change current code or database).
3. Using isolated temporary Caddyfile and **hotfix php.ini**, test under actual FrankenPHP web SAPI with `ini_get('curl.cainfo')`, `ini_get('openssl.cafile')`, one bounded SEC GET, TLS verification **enabled** and no redirects; require HTTP 200, valid `companyfacts`, expected CIK and nonempty body.
4. After CI and Windows acceptance PASS, merge PR with normal two-parent merge.
5. Deployment: backup SQLite and runtime .env, fast-forward only to verified merge commit, copy tracked `deploy/php.ini` and restart the managed II processes via official scripts; verify `/up`, DB integrity, and a real browser SEC fetch. Preserve all gate rules.

**Never** set `verify=false`, `CURLOPT_SSL_VERIFYPEER=0`, `CURLOPT_SSL_VERIFYHOST=0`, or untrusted proxy-root workarounds. No secrets or downloaded SEC JSON should be stored by acceptance.

## Permanent application-level controls (not repeatable one-off fixes)

- Managed `start-ii.ps1` reports `II_SEC_CA_BUNDLE=READY` or a specific warning each start; keeps offline research usable even if external SEC is unavailable.
- `SecOfficialCompanyFactsService::tlsOptions()` explicitly pins Guzzle's `verify` option to the canonical runtime CA bundle when running under FrankenPHP on Windows. The service fails closed with `SEC_TLS_CA_MISSING` or `SEC_TLS_CA_INVALID`, rather than hiding the TLS root cause as generic downtime. Neither disables certificate or hostname verification.
- `php artisan ii:doctor --email=<local account>` reports SQLite integrity, whether this exact account exists, its research role, **account-owned** documents and periods (period ownership is derived through its parent issuer document), global document count for the local operator, and runtime CA readiness without modifying data. `--strict` returns nonzero on unmet prerequisites. The doctor does not request SEC or reveal a password.
- Login copy now correctly identifies V11 (not hardcoded VS6). The Fundamental activation workspace explains that totals are **per logged-in account** and normalized SEC CSV is not imported automatically. Do not merge accounts or reassign data without verified authority.
- Regression tests include live-service TLS configuration boundary (mocked), user-vs-database counts, and all existing V11 SEC access tests. Only isolated Windows FrankenPHP HTTPS acceptance establishes actual trust store interoperability.
- Reuse policy: Laravel session authentication, Eloquent, Illuminate HTTP Client/Guzzle, and Composer were already present. `composer/ca-bundle` was evaluated as an established MIT-licensed alternative, but adding a second CA distribution and altering `composer.lock` is unnecessary when an officially sourced Mozilla PEM, explicit Guzzle `verify` path and managed launcher fix the observed web-SAPI error. Keep runtime CA renewal in standard operating maintenance, not a new data acquisition engine.
