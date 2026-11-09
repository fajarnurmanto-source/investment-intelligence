# Post-Laravel canonical habitat verification — 10 November 2026

## Scope

One focused acceptance pass covered the canonical Git worktree, external local runtime, Laravel-only dependency boundary, launcher lifecycle, authenticated FRED journey, and GitHub synchronization preconditions.

## Results

| Check | Result | Evidence |
|---|---|---|
| Canonical worktree | PASS | `git rev-parse --show-toplevel` resolves to `D:\Research\INVESTMENT RESEARCH\II\repository`. |
| Runtime separation | PASS | `.env`, SQLite, storage files, caches, logs, views, and Composer vendor live under `II\runtime\laravel`; move hashes matched. |
| Laravel-only boundary | PASS | No Python, FastAPI, Uvicorn, subprocess, port 8811, or `II_ENGINE` reference in active Laravel app/config/routes/resources or launchers. |
| Laravel tests | PASS | PHPUnit 12.5.40: 7 tests, 31 assertions. |
| Dependency security | PASS | `composer audit --locked`: no security vulnerability advisories. |
| Routes | PASS | 30 `/ii` product routes available. |
| Launcher start | PASS | Migration reported nothing pending; queue, scheduler, and server started at `127.0.0.1:8810`. |
| Launcher stop | PASS | `/up` became unreachable after Ctrl+C; launcher cleanup stopped child processes. |
| Launcher restart | PASS | Cache reset, migration, and server startup succeeded on the second invocation. |
| Authenticated user journey | PASS | Login page 200, login final response 200, FRED dashboard 200, FRED CSV report 200. Both `CPIAUCSL` and `FEDFUNDS` were present. Synthetic journey user was removed afterward. |
| Final stop | PASS | Port endpoint was unreachable after the restarted launcher was stopped. |
| Source hygiene | PASS | `.env`, runtime SQLite, vendor, cache, logs, storage data, and backups are not staged or tracked. |
| GitHub push–pull | PASS | Review branch was created without force-push; the repeatable helper completed fetch, fast-forward-only pull, push, and local/remote HEAD comparison. |

The launcher failures encountered during this pass were limited to Windows path quoting and stale serialized cache after the runtime move. Those paths were corrected, cache payload timestamps were normalized to strings, and only the affected checks were repeated.
