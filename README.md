# Investment Intelligence

Investment Intelligence (II) is a Laravel-only local research product. Its active backend, ingestion, analytics, authorization, queue, scheduler, dashboard, and reports run in PHP/Laravel; no Python or FastAPI runtime is required.

## Architecture decision (24 November 2026)

**Local BYOK-first:** II is a local Laravel research application installed separately for each independent user. No shared developer-owned market-data key, no centralized market-data relay and **no multi-device data synchronization**.

The first provider adapter supports MarketData.app delayed quotes from the authenticated user's own key via a localhost-only workflow at `/ii/data-sources` after login. API keys are encrypted in the local database using the device-specific Laravel APP_KEY. BYOK market quotes are transient, display-only: no export, clipboard feature, cloud LLM transfer, raw archive, background fetch or import to canonical price tables. MarketData user agreements may impose additional restrictions; the installer and DPAPI hardening are still pending.

**Do not use the old idxapi.io Free shared-key sync workflow:** it has been removed. SEC/FRED sources and independently uploaded research retain separate usage rights. Git synchronization commands in this README apply only to application **source code**, never licensed market data.

Architecture record: [II Local BYOK Mandatory Architecture](docs/ops/II_BYOK_LOCAL_MANDATORY_ARCHITECTURE_20261124.md).

## Canonical habitat

- Habitat: `D:\Research\INVESTMENT RESEARCH\II`
- Git worktree: `D:\Research\INVESTMENT RESEARCH\II\repository`
- Runtime: `D:\Research\INVESTMENT RESEARCH\II\runtime\laravel`
- Local URL: `http://127.0.0.1:8810`
- GitHub: `fajarnurmanto-source/investment-intelligence`

The runtime contains the private `.env`, APP_KEY, SQLite database, writable storage, installed Composer dependencies, caches, and logs. It is excluded from Git.

## Windows PowerShell runtime compatibility (25 November 2026)

The supported local startup path works with both **Windows PowerShell 5.1** (`powershell.exe`) and **PowerShell 7** (`pwsh`). The runtime helper no longer uses the newer `Start-Process -Environment` parameter, which caused a successful deployment/migration to fail at FrankenPHP startup under Windows PowerShell 5.1. It temporarily scopes child-only environment variables before process creation and restores the parent's original process variables in a `finally` block.

Use the existing `start-ii.ps1`, `stop-ii.ps1`, and `restart-ii.ps1` scripts. If this Windows account blocks local scripts, adjust Execution Policy **for the current process only** or launch a trusted source-controlled script using `pwsh -NoProfile -ExecutionPolicy Bypass -File ...`; do not require machine-wide policy changes. An example within the existing PowerShell session is `Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force`.

During upgrades, `Deploy-IIProduction.ps1` still requires a clean worktree and preserves the existing runtime APP_KEY/SQLite; it creates a recovery point before Composer/migration. After a successful one-time deployment, **do not rerun Composer/migration merely to restart II**; use `start-ii.ps1 -SkipMigrate` or `restart-ii.ps1 -SkipMigrate` instead. Never run `key:generate` on an established device.

Windows CI tests in `tools/production/tests/` run real `powershell.exe` and `pwsh` processes to assert child-only environment inheritance, parent restoration after success/failure, and denial of occupied ports with missing process manifests. These synthetic tests do not change the user's installation and are not a substitute for real-device launcher/dashboard acceptance.

## Operations

Run from PowerShell in the repository:

```text
.\tools\production\Deploy-IIProduction.ps1
.\tools\production\Install-IICaBundle.ps1
.\start-ii.ps1
.\stop-ii.ps1
.\restart-ii.ps1
```

The production launcher uses FrankenPHP/Caddy bound to localhost and supervises the web server, database queue worker, and scheduler through a PID manifest. It does not use `artisan serve`.

Production deployment creates a current recovery point, preserves APP_KEY/SQLite/storage, and installs the official curl/Mozilla CA bundle with upstream SHA-256 verification. Local account operations are provided by `php artisan ii:user`; the read-only readiness gate is `php artisan ii:doctor --email=<account> --strict`.

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
- immutable issuer-source registration, CSV/XBRL/PDF intake, provenance/usage-right status, quality gates, nullable valuation, historical comparison, and CSV exports ([V1 guide](docs/data/ISSUER_FUNDAMENTAL_INTELLIGENCE_V1.md));
- queued multi-issuer manifests, item-level retry/failure evidence, source-version conflicts, dated market-price provenance, sector/period comparison, and auditable ratio sorting ([V2 guide](docs/data/MULTI_ISSUER_FUNDAMENTAL_INTELLIGENCE_V2.md));
- reconciled Telkom FY2024/FY2023 metric definitions and source locations ([reconciliation record](docs/data/TELKOM_FY2024_RECONCILIATION.md));
- signal, strategy, portfolio, VaR/CVaR, and correlation analytics;
- real FRED ingestion with provenance, timestamps, quality state, retries, dashboard, and CSV reporting;
- queue, scheduler, cache, events, Eloquent, and report export.

Imported or insufficiently qualified evidence remains `NOT_ADMITTED`; research decisions remain `HOLD`; trading authority remains `NONE`. No automatic trading or paid-data activation is enabled.

Historical source remains recoverable from Git history and the verified local recovery point; it is not present in the active branch or habitat paths.
