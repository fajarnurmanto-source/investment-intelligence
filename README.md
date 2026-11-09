# Investment Intelligence — Laravel-only acceleration

Investment Intelligence (II) now runs its active backend exclusively on Laravel. The previous FastAPI/Python source remains in the repository only as a rollback checkpoint and is not called by routes, services, the launcher, CI, queues, or scheduler.

## Working product capabilities

- authenticated research workspace and persistent research cases;
- issuer fundamental CSV ingestion and transparent multi-factor screener;
- OHLCV signal analysis, strategy replay/validation, risk budget, and portfolio analytics;
- historical VaR/CVaR and correlation analytics powered by `markrogoyski/math-php`;
- real FRED macro pipeline with source provenance, checksums, quality state, dashboard, and CSV report;
- Laravel jobs, database queue, scheduler, cache invalidation, events, Eloquent, authorization middleware, and HTTP client.

## Start

Run `start-ii.ps1` from PowerShell. It prepares the local Laravel environment without overwriting an existing `.env`, applies non-destructive migrations, starts the queue and scheduler, then serves II at `http://127.0.0.1:8810`.

To ingest allow-listed FRED series immediately:

```text
cd laravel_app
php artisan ii:ingest-fred --sync
```

## Safety boundary

Imported issuer and price data remain `USER_IMPORTED_UNVERIFIED`; research state is `HOLD`; trading authority is `NONE`. The macro connector is limited to two configured public economic series and links the applicable source terms. No paid service, broker integration, production deployment, or destructive database migration is enabled.

## Rollback

The pre-cutover Python/FastAPI files (`app/`, `legacy/`, `requirements.txt`, and Python tests) are retained unchanged. They are historical fallback material, not an active runtime. The cutover branch is `feature/ii-laravel-only-acceleration-20261107`.
