# Investment Intelligence

Investment Intelligence (II) is a Laravel-only local research product. Its active backend, ingestion, analytics, authorization, queue, scheduler, dashboard, and reports run in PHP/Laravel; no Python or FastAPI runtime is required.

## Canonical habitat

- Habitat: `D:\Research\INVESTMENT RESEARCH\II`
- Git worktree: `D:\Research\INVESTMENT RESEARCH\II\repository`
- Runtime: `D:\Research\INVESTMENT RESEARCH\II\runtime\laravel`
- Local URL: `http://127.0.0.1:8810`
- GitHub: `fajarnurmanto-source/investment-intelligence`

The runtime contains the private `.env`, APP_KEY, SQLite database, writable storage, installed Composer dependencies, caches, and logs. It is excluded from Git.

## Operations

Run from PowerShell in the repository:

```text
.\tools\production\Deploy-IIProduction.ps1
.\start-ii.ps1
.\stop-ii.ps1
.\restart-ii.ps1
```

The production launcher uses FrankenPHP/Caddy bound to localhost and supervises the web server, database queue worker, and scheduler through a PID manifest. It does not use `artisan serve`.

Use the tracked synchronization helper at `tools\sync-II.ps1`:

```text
.\tools\sync-II.ps1 -Mode Status
.\tools\sync-II.ps1 -Mode Pull
.\tools\sync-II.ps1 -Mode Push
```

It permits fast-forward pulls and feature-branch pushes only; direct pushes to protected branches and force-pushes are blocked.

## Working capabilities

- authenticated research workspace and role authorization;
- issuer fundamentals ingestion and screening;
- signal, strategy, portfolio, VaR/CVaR, and correlation analytics;
- real FRED ingestion with provenance, timestamps, quality state, retries, dashboard, and CSV reporting;
- queue, scheduler, cache, events, Eloquent, and report export.

Imported or insufficiently qualified evidence remains `NOT_ADMITTED`; research decisions remain `HOLD`; trading authority remains `NONE`. No automatic trading or paid-data activation is enabled.

Historical source remains recoverable from Git history and the verified local recovery point; it is not present in the active branch or habitat paths.
