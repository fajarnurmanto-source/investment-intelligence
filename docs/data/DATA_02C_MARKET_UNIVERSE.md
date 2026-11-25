# DATA-02C — bounded market universe

Use the existing runtime .env and the rights/provider settings from DATA-02A/B. Default remains disabled. An optional II_MARKET_FEED_UNIVERSE_JSON overrides the single ticker selection, not the shared owner, source, credentials or license. For example (illustrative mapping only, not confirmed entitlement):

```
II_MARKET_FEED_REQUEST_BUDGET=2
II_MARKET_FEED_UNIVERSE_JSON='[{"ticker":"AAPL","provider_symbol":"AAPL","provider_mic":"XNAS"},{"ticker":"MSFT","provider_symbol":"MSFT","provider_mic":"XNAS"}]'
```

Each item can override currency, price_adjustment and session_timezone. Verify provider identifiers and rights for every instrument before enabling. One provider, owner and credential configuration per installation in this phase. Unknown fields, duplicate canonical tickers and duplicate API identities are rejected before fetching. The list is ordered and capped at the operator's request budget (1–50, default 10). This cap counts instruments per run, not provider credits per minute/day. Configure a conservative budget appropriate for the subscription. Rate limiting or entitlement denial stops the rest of the run; other instrument failures are reported separately. No automatic retry yet.

Run ii:sync-market-feed for the list, or ii:sync-market-feed --ticker=MSFT for one registered instrument. Legacy single-ticker configuration and output remain supported. Existing schedule and rights gates remain in force. No database migrations or UI changes. Credentials remain in protected runtime configuration pending vault integration. Tests use synthetic responses, not actual market data. Never commit credentials or licensed price data.

Next: secure credential vault, persistent receipts, provider-specific quota/backoff, multi-provider registry, UI and live acceptance.
