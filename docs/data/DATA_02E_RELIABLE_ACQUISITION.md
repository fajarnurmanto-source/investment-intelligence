# DATA-02E.1–3 — Local credential and acquisition ledger

Extends the existing owner-scoped credential vault with a separate Twelve Data profile. Windows DPAPI CurrentUser remains mandatory; Linux development uses Laravel Crypt. No marketdata rights change. Credentials are not licenses.

This supersedes the API key environment configuration in DATA-02B. Remove II_MARKET_FEED_API_KEY from runtime .env and refresh configuration cache; no fallback to plaintext configuration. After controlled migration and setting owner/provider configuration, run php artisan ii:connect-market-feed interactively on the local Windows runtime under the same Windows identity as the scheduler. Enter the authorized key at the hidden prompt, never in arguments or chat. --disconnect removes only the configured owner's Twelve Data key.

II_MARKET_FEED_DAILY_REQUEST_LIMIT defaults to 10, valid 1–10000. Atomic persistent counter is installation-wide per provider per UTC day. Reserves one attempt before each HTTP request, including failed requests. It is an internal conservative cap, not verified provider credit accounting or a minute-rate limit. Do not configure above contractual limits. Batch halts on budget exhaustion, missing credentials, rate limiting or entitlement denial.

New migration adds ii_market_request_days and ii_market_acquisition_receipts. Receipts record valid owner, ticker, provider, start/end and sanitized status for command-driven runs. No raw price payloads, secrets or HTTP response bodies. Interrupted runs may remain RUNNING and require later reconciliation. No destructive migration or changes to existing data. Keep acquisition disabled until licensing and local acceptance.

Remaining: queue execution, retry/backoff, stale-run recovery, calendar-aware coverage, configuration UI, real provider acceptance. Tests are synthetic.