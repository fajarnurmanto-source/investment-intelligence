# II Personal Research Terminal — M08 Windows Installer Runtime Audit
Date: 2026-11-24
Status: DESIGN/AUDIT; NOT A SHIPPED INSTALLER
Source: GitHub branch feature/ii-v13-idxedge-free-pilot-20261124

## Existing reusable runtime assets (verified from source)
- `start-ii.ps1` launches local FrankenPHP/Caddy, then database queue worker and Laravel scheduler, recording managed PIDs and verifying `/up` responds HTTP 200; default 127.0.0.1:8810.
- `tools/production/Deploy-IIProduction.ps1` requires clean worktree and optional exact commit, creates recovery point, validates CA trust, preserves APP_KEY, configures SQLite/database sessions/queue, installs Composer dependencies and migrates, then starts managed processes.
- `laravel_app/composer.json`: PHP ^8.3; Laravel ^13; League CSV 9.29; math-php 2.12. Existing product runs server-side PHP/Laravel, no required Python backend.
- Existing `stop-ii.ps1`, `restart-ii.ps1`, `tools/production/II-Production.Common.ps1` and `Install-IICaBundle.ps1` are reusable orchestration foundations.

## Installer-specific gaps (do not claim ready)
- An installer cannot assume Git, Composer, npm, system PATH PHP, or developer terminal on a fresh PC. Package vetted runtime binaries, extensions, vendor dependencies and built front-end assets (subject to redistribution licensing), or explicitly bootstrap trusted signed dependencies.
- Current production script enforces an existing APP_KEY and assumes a developer repository/worktree. First-install path must securely generate per-device APP_KEY, initialize SQLite and owner account without copying existing production secrets/database.
- Per-device immutable installation UUID, owner enrollment and revocation remain planned; no shared session cookies, APP_KEY, live SQLite file or vendor tokens.
- Service management: supervise web/queue/scheduler across login/restart; graceful stop before upgrades; process PID lifecycle, per-device port collision, Windows permissions and firewall loopback-only.
- Storage layout: immutable application binaries vs writable per-user ProgramData/AppData; restrict ACL for `.env`, evidence and backups; archive integrity and secure deletion decisions.
- Signed release manifest, integrity verification, signature checks, release rollback, migrations backup and crash recovery are not implemented by existing PowerShell launcher.
- Offline operation must degrade cleanly when API credentials/market network unavailable, with explicit LIVE/DELAYED/EOD/UNKNOWN badges and source entitlement gates.
- Uninstaller must offer explicit keep/export/delete personal data options, never delete without confirmation.
- Verify legal redistribution of FrankenPHP, PHP builds/extensions, CA bundle and each packaged library.

## Proposed M08 delivery slices
M08-A: package inventory, dependency hashes, signed-source verification, tested Windows versions and bitness; make installer build reproducible.
M08-B: fresh-install wizard + per-device app secret + separate SQLite + localhost secure launcher + crash-safe migrations + health checks; no need for Git/Composer on target.
M08-C: start/stop/restart UI, queue/scheduler supervision, log viewer (secrets redacted), user account recovery and offline status.
M08-D: signed versioned installer, upgrade from previous version and rollback to backup, clean uninstall semantics.
M08-E: device A and device B on different networks; separate keys/DB, rights-aware data usage, no sync until separately accepted.

## Concrete acceptance checklist
1. Install on clean Windows VM without Git, Composer, npm or PHP pre-installed; launch with normal desktop shortcut; `/up` healthy.
2. First-run enrollment generates a unique APP_KEY and local SQLite, user secret protected; no demo securities/data masquerading as real market feeds.
3. Restart, Windows logoff/logon, offline mode, process crash, occupied port and low disk space tested.
4. Backup + restore tested; rollback from failed upgrade preserves evidence and owner data.
5. Second machine uses distinct credentials and local data; does not duplicate provider entitlements or leak source archives.
6. All scenarios run from exact signed build SHA, with recorded Windows build, install logs, UX screenshots, test counts and manual approval.

## Immediate critical dependency
Retain production deployment scripts for the existing personal PC; build an installer staging toolchain in a separate feature branch after V13 acceptance closure. Do not replace running scripts or deploy from the V13 PR.
