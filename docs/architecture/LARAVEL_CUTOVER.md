# Laravel cutover record

Active runtime components are under `laravel_app/`. Company Research, reviewer workspace, research cases, scenario evaluation, fundamental analysis, signals, strategy validation, risk analytics, macro ingestion, reporting, and authentication execute in PHP/Laravel.

The root Python/FastAPI files are retained unchanged for rollback evidence. They are excluded from the launcher and CI and are not called by the active Laravel application. The active launcher uses PHP only and starts Laravel serve, queue worker, and scheduler worker.

Cutover verification commands:

```text
php artisan migrate:fresh --env=testing
php artisan test
php artisan route:list
php artisan ii:ingest-fred --sync
```

The final repository scan must find no `127.0.0.1:8811`, `FastAPI`, Python process, or engine URL in `laravel_app/app`, `config`, `routes`, and active views (historical comments/documentation excluded from the runtime assertion).
