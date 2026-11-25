# DATA-02A/B — Licensed local OHLCV feed (candidate)

Default OFF. This is automatic ingestion of an independently supplied rolling snapshot, not an exchange downloader, vendor contract, or live data acceptance. The existing MarketData.app BYOK policy remains memory-only and user-initiated. Do not relabel restricted data as independent.

Configure the runtime .env only after confirming contractual rights for automated acquisition, persistent local storage, and internal analysis. The operator must own the designated account and be authorized to configure this local installation.

- II_MARKET_FEED_ENABLED=true
- II_MARKET_FEED_OWNER_ID=<existing authorized user ID>
- II_MARKET_FEED_TICKER=<canonical ticker>
- II_MARKET_FEED_SOURCE_NAME=<actual publisher>
- II_MARKET_FEED_SOURCE_URL=<publisher HTTPS URL without credentials or query>
- II_MARKET_FEED_RIGHTS_REFERENCE=<non-secret contract or rights evidence identifier>
- II_MARKET_FEED_STORAGE_PERMITTED=true
- II_MARKET_FEED_AUTOMATION_PERMITTED=true
- II_MARKET_FEED_CURRENCY=<ISO currency>
- II_MARKET_FEED_ADJUSTMENT=unadjusted (or adjusted, according to publisher)
- II_MARKET_FEED_TIMEZONE=<supported exchange timezone>

Do not commit .env, licensed source rows or credential data. Refresh the existing production configuration cache using the supported deployment workflow. The authorized external publisher/exporter must atomically replace the private local-disk file at ii_market_feed/u<owner ID>/<ticker>.csv; no external download is implemented in this candidate. Exact header: date,open,high,low,close,volume. Supply 60–2000 completed sessions, max 1 MiB, no current-day or future session. Same source metadata and overlapping historical rows must agree. Conflicts are rejected without replacing previous history. Manual series are never silently superseded by this feed. A changed source or corporate-action restatement needs separate review.

Run ii:sync-market-feed to inspect status; scheduling is every 15 minutes when enabled. Immutable normalized documents enter the existing private Signal Center and lineage registry; unchanged snapshots do not create versions. No migration. Evidence stays USER_IMPORTED_UNVERIFIED, HOLD, trading authority none. Unit fixtures are synthetic, not real market observations.

Remaining: vendor entitlement review, network adapter, multi-symbol registry, durable per-run status, full real-data acceptance and coverage/freshness improvements.
