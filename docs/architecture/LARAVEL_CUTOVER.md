# Laravel-only architecture record

The active II application is `laravel_app/`. Controllers, services, jobs, queues, scheduler tasks, HTTP ingestion, cache invalidation, events, persistence, authentication, authorization, analytics, and reporting all execute in Laravel.

The historical Python/FastAPI implementation and hybrid overlays were retired from the active branch on 10 November 2026. Their history remains recoverable through Git and the verified recovery point. Active source, CI, launchers, routes, services, and operational scripts do not start Python, FastAPI, Uvicorn, subprocess Python, or port 8811.

Local production uses FrankenPHP 1.13.5 with its Caddy server, PHP 8.5, a production `php.ini`, and localhost-only binding. The launcher maintains three managed processes: web, Laravel database queue worker, and Laravel scheduler. Stop and restart operate only on recorded PIDs and verify that no managed process is left orphaned.

The production runtime is external to Git at `D:\Research\INVESTMENT RESEARCH\II\runtime\laravel`. This preserves `.env`, APP_KEY, SQLite/FRED data, storage, logs, and installed packages independently from source updates.

Verification evidence on 10 November 2026:

- PHPUnit: 7 tests, 31 assertions;
- Composer advisory scan: no known security advisories;
- environment: `production`, debug off;
- SQLite integrity: `ok`;
- FRED observations: 1,824;
- authenticated login → dashboard → FRED dashboard → CSV report: PASS;
- launcher stop → start with new PIDs and no orphans: PASS.
