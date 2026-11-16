# Local production operations

## Deployment profile

- Environment: `production`
- Debug: disabled
- Bind: `127.0.0.1:8810` only
- Server: FrankenPHP/Caddy
- Persistence: external SQLite runtime
- Background work: Laravel database queue and scheduler workers

Run `tools\production\Deploy-IIProduction.ps1 -ExpectedCommit <verified-merge-sha>` after a source update. The script refuses a dirty or unexpected worktree, creates a new hash-manifested recovery point, installs a checksum-verified curl/Mozilla CA bundle, preserves `.env`, APP_KEY, SQLite and user storage, stops managed services, installs production Composer dependencies, applies non-destructive migrations, builds Laravel caches, and restarts all managed services.

Use `start-ii.ps1`, `stop-ii.ps1`, and `restart-ii.ps1` for normal operation. Process IDs are recorded at `runtime\server\processes.json`; stop targets only those IDs. Service logs are under `runtime\laravel\storage\logs\services`.

The health endpoint is `http://127.0.0.1:8810/up`. A successful start requires HTTP 200. If startup fails, the launcher stops any process it started and returns an error.

Use `php artisan ii:doctor --email=<local-account> --strict` for the read-only integrity/readiness gate. Use `php artisan ii:user` for status, creation, password reset and role assignment; never edit the users or sessions tables manually. Password reset retains the account ID and owned research data while invalidating that account's active sessions.

## Recovery

Historical recovery evidence remains under `D:\Research\INVESTMENT RESEARCH\II\recovery`. Every current deployment creates a timestamped `PROD_*_AUTO_PREDEPLOY` point containing a consistent SQLite backup, private environment/configuration, user storage, current launcher/config files, CA bundle metadata when present, and a SHA-256 manifest. Verify `manifest.sha256` before using a point for recovery.

## Safety invariants

- Evidence without required qualification remains `NOT_ADMITTED`.
- Research decisions remain `HOLD` until human review changes them.
- Trading authority is `NONE`; no automatic order execution exists.
- Paid services and production billing remain disabled.
