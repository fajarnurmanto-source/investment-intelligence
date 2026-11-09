# II canonical habitat

Effective 9 November 2026, the only active Investment Intelligence worktree is:

`D:\Research\INVESTMENT RESEARCH\II\repository`

The origin remote is `https://github.com/fajarnurmanto-source/investment-intelligence.git`. The application source, tests, launchers, synchronization helper, product documentation, migrations, and source assets are Git-tracked here.

Local state is deliberately outside Git:

- environment, SQLite database, writable storage, logs, cache, sessions, views, and installed Composer dependencies: `D:\Research\INVESTMENT RESEARCH\II\runtime\laravel`;
- frozen backups and rollback material: retained in their existing protected locations;
- previous Git delivery repositories: classified as `ARCHIVED` or `PROTECTED`, never active source.

## Commands

- Start: `D:\Research\INVESTMENT RESEARCH\II\repository\start-ii.ps1`
- Synchronize: `D:\Research\INVESTMENT RESEARCH\II\repository\sync-II.ps1`
- Read-only sync status: `D:\Research\INVESTMENT RESEARCH\II\repository\sync-II.ps1 -Mode Status`

The synchronization helper checks the canonical worktree and remote, rejects tracked runtime/secrets/dependencies, fetches remote state, permits fast-forward-only pull, and blocks direct pushes to `main`, `master`, and `development`.
