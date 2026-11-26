# DATA-02D — historical lookback and incremental checkpoint candidate

Set II_MARKET_FEED_HISTORY_SESSIONS to an integer 60–2000 (default 250). Illustrative targets: 250, 750, 1250 sessions for roughly one, three, five years. Actual coverage depends on the provider, instrument listing age and contractual entitlement. No live entitlement is assumed.

II_MARKET_FEED_INCREMENTAL_ENABLED defaults true. Once a compatible stored series reaches its configured target, the next request starts 14 calendar days before its last observation and ends yesterday in the exchange timezone. If the target increases beyond stored coverage, a full rolling request is used again. Set incremental_enabled=false to request the rolling window explicitly.

Existing source identity and metadata are checked before network acquisition. An initial series requires at least 60 valid observations; subsequent API responses may be shorter, must overlap existing observations and must not regress the latest date. Conflicting overlap or disconnected history is rejected; no speculative corporate-action adjustment. Identical merged histories create no new version. Stored documents remain immutable with a 2000-row active-series limit and coverage/request-window metadata. Local feed behavior remains unchanged.

This is a bounded single-request backfill, not a paginated historical crawler. Exchange-calendar gap detection, missing-session reconciliation, persistent success/failure receipts, provider quotas and retry, credential vault, and live acceptance remain pending. Tests use synthetic fixtures. Default acquisition remains disabled; no migration.
