# DATA-02B — Twelve Data daily API candidate

Official reference: https://twelvedata.com/docs ; terms: https://twelvedata.com/terms ; https://support.twelvedata.com/en/articles/5332349-commercial-and-personal-usage .

This adapter does not establish an entitlement. Enable only after confirming subscription and exchange rights for automated requests, persistent local history (including retention after subscription expiry where applicable), internal analysis and display. Temporary caching alone is insufficient. No redistribution, export or cloud transfer is granted by this adapter. No real account/key has been tested.

Use the existing private runtime .env, never Git or chat. Set the DATA-02A rights and owner fields, plus:
- II_MARKET_FEED_PROVIDER=twelve_data
- II_MARKET_FEED_SOURCE_NAME="Twelve Data"
- II_MARKET_FEED_SOURCE_URL=https://api.twelvedata.com/time_series
- II_MARKET_FEED_PROVIDER_SYMBOL=<provider symbol>
- II_MARKET_FEED_PROVIDER_MIC=<verified exchange MIC>
- II_MARKET_FEED_API_KEY=<authorized key>
- II_MARKET_FEED_ADJUSTMENT=unadjusted
- II_MARKET_FEED_CURRENCY and II_MARKET_FEED_TIMEZONE must match returned metadata.

Default remains OFF/local. The runtime config cache may contain the key: protect it with local ACLs; credential vault integration remains pending. Do not share config cache. Daily schedule 12:00 UTC, requests up to 250 completed daily bars ending yesterday exchange-local, no intraday data. Fixed HTTPS host; header authentication; redirects disabled. No same-day data. API identity and schema mismatches, entitlement denial and throttling fail closed. Existing private immutable signal pipeline validates rows, merges compatible history and rejects conflicts. No automatic source changes. Provider response digest is stored, not raw response. Tests use synthetic HTTP fixtures.

Remaining: multi-symbol registry, secure credential vault, provider entitlement and retention lifecycle, durable run receipts, retry/backoff, live acceptance, verified IDX coverage. No claim of real data availability or completed DATA-02.
