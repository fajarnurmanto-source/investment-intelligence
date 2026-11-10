# Local production operations

## Deployment profile

- Environment: `production`
- Debug: disabled
- Bind: `127.0.0.1:8810` only
- Server: FrankenPHP/Caddy
- Persistence: external SQLite runtime
- Background work: Laravel database queue and scheduler workers

Run `tools\production\Deploy-IIProduction.ps1` after a source update. The script preserves the existing `.env` and APP_KEY, installs production Composer dependencies, applies non-destructive migrations, builds Laravel caches, and restarts all managed services.

Use `start-ii.ps1`, `stop-ii.ps1`, and `restart-ii.ps1` for normal operation. Process IDs are recorded at `runtime\server\processes.json`; stop targets only those IDs. Service logs are under `runtime\laravel\storage\logs\services`.

The health endpoint is `http://127.0.0.1:8810/up`. A successful start requires HTTP 200. If startup fails, the launcher stops any process it started and returns an error.

## Recovery

The pre-deployment recovery point is `D:\Research\INVESTMENT RESEARCH\II\recovery\PROD_20261110_064105`. It contains the environment file, FRED SQLite database, storage data, a Git bundle, hashes, and retirement manifests. SQLite integrity and Git bundle verification passed before deployment.

## Safety invariants

- Evidence without required qualification remains `NOT_ADMITTED`.
- Research decisions remain `HOLD` until human review changes them.
- Trading authority is `NONE`; no automatic order execution exists.
- Paid services and production billing remain disabled.
