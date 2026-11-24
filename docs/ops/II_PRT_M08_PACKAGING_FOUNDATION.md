# II — Local BYOK Windows Installer Foundation (M08)

Updated: 24 November 2026
Status: DRAFT; source staging and synthetic CI, **NOT an installable/signed release**.

**Current mandate:** local BYOK-first research terminal, one local installation per independent subscriber. Previous same-owner multi-device synchronization milestone is RETIRED. Each independent installation must use its own APP_KEY/SQLite and owner credentials; no shared market-data data cache or API key and no inter-device sync.

## Reusable implementation
- `Test-IIPersonalPackageInputs.ps1`: checks Laravel, FrankenPHP, PHP CLI/extensions, vendor and compiled assets before staging.
- `Build-IIPersonalStagingPayload.ps1`: allowlisted unsigned staging with manifest SHA-256; blocks source secrets, .env, databases, uploads and excess files.
- `Test-IIPersonalStagingIntegrity.ps1`: independent hash/manifest/undeclared-file gate.
- `Initialize-IIPersonalDeviceState.ps1`: **unsigned staging test-only** initialization with unique APP_KEY, device UUID, local SQLite placeholder and protected Windows ACL.
- `Invoke-IIPersonalDeviceMigration.ps1`: explicit **test-only** bundled PHP migration workflow, no production activation proven.
- `IIPersonalEnrollOwner`: hidden-password CLI account setup via the existing Laravel Users table, no public registration.
- Tests under `tools/installer/tests/` use two disposable isolated fixture folders solely to verify that independent installations do not share keys. **These are not multi-device sync tests.**

## Release gates still open
1. Real full-package PHP + Laravel migration and clean-Windows VM login/dashboard/`/up` acceptance.
2. Windows ACL review of database, .env and key lifecycle; evaluate native DPAPI vault rather than only Laravel Crypt.
3. Signed installer, binary redistribution rights, portability, Windows service lifecycle, upgrades/rollback/uninstallation.
4. BYOK onboarding UX, accurate entitlement status, provider terms review, no cloud relay, output-rights audit across every export.
5. Distinct **independent end users** with individual accounts and keys; never license pool.

The code sync utility `tools/sync-II.ps1` is for the developer Git repository only, and does not copy research-market data.

## Verification
Early synthetic Windows packaging stages passed CI #16, #20 and #25. Subsequent BYOK cutover must pass its own CI. No live market-data key, production database, hardware installation or trading action was touched.

See [mandatory local BYOK architecture](II_BYOK_LOCAL_MANDATORY_ARCHITECTURE_20261124.md) for current canonical decision and earlier history in Git.


## Local owner bootstrap and verified SQLite snapshots — 25 November 2026

- `IIPersonalEnrollOwner` now writes `device.json` **after** successfully creating exactly one owner. Before touching the account, production enrollment checks the actual connected SQLite DB resolves to this initialized device's `database/ii.sqlite`, preventing a stale `DB_DATABASE` from enrolling a different database. Device metadata is published via a same-directory temporary file and rename.
- The published state becomes `owner_enrolled=true`, `activation_status=OWNER_ENROLLED_PENDING_RUNTIME_ACCEPTANCE`, `login_ready=false` and `runtime_accepted=false`. This **does not falsely claim that the app server, login, BYOK connectivity or Windows installer was accepted**. Only a separate, future real-device acceptance may set those markers.
- `tools/installer/Backup-IIPersonalDevice.php` implements a **manual same-owner, same-device, non-portable SQLite-only snapshot** with SQLite `VACUUM INTO` and `PRAGMA integrity_check`. CLI usage: `php tools/installer/Backup-IIPersonalDevice.php <PRIVATE_DEVICE_DATA_ROOT>`. Backup is only allowed after migration + owner registration metadata are complete, and stored under `<device>/database/backups/ii-*.sqlite`. The database source is not replaced or migrated.
- Snapshots remain sensitive SQLite files, potentially containing encrypted BYOK keys and research data. They are **not independently encrypted backup archives**. Windows security relies on the existing protected device-root ACL and Windows DPAPI `CurrentUser` context; they are not portable to a different Windows account or installation, and cannot decrypt without that owner's DPAPI identity. No `.env` or APP_KEY is copied into backups.
- Backup names are randomized and never overwrite existing files. Both the original and resulting SQLite database are checked for structural integrity; failed/incomplete snapshot files are cleaned up. No automatic restoration has been enabled because a safe restore must independently verify a closed service, matching owner/APP_KEY/DPAPI identity, SQLite WAL state, and rollback capability. Do not manually overwrite a live database.
- New PHPUnit tests cover missing owner-state rejection, consistent duplicate-independent snapshots, checksum verification, source preservation, absence of APP_KEY in receipt/output, and atomic owner-state publication with duplicate activation refused.
- **Release limitations:** this is local bootstrap and backup code, not a signed, distributable Windows installer or clean-machine browser acceptance. Production install, rollback/restore, hardware checks and live private API-key testing remain open.

- **Cleanup:** the retired `II_IDXEDGE_AUTO_SYNC=false` entry was removed from the new-device `.env` template. Windows device bootstrap tests assert no obsolete IDX Edge auto-sync, IDXAPI shared key, or shared BYOK env entry is written. Developer source Git-sync tools remain intentional and must not be confused with user-market-data synchronization.
- **Backup release gate:** the SQLite-only backup utility is directly tested on both Linux and Windows CI using a disposable synthetic database; the helper deliberately does **not** perform restores and is not a substitute for same-owner Windows DPAPI recovery, verified launcher/browser smoke testing, or signed installer acceptance.


## V9 — Read-only SQLite restore preflight (25 November 2026)

- Added `tools/installer/Verify-IIPersonalRestore.php` as a **read-only** local restore eligibility check. Usage: `php tools/installer/Verify-IIPersonalRestore.php <PRIVATE_DEVICE_DATA_ROOT> <ii-backup-filename.sqlite> <EXPECTED_BACKUP_SHA256>`.
- The tool accepts only backup basenames in the application's own timestamp-and-random format, located under the same protected device-root `database/backups/`; rejects unexpected/symlink backup paths, uninitialized/unenrolled devices, missing private APP_KEY, altered SHA-256, SQLite corruption/schema mismatch and mismatched SQLite `user_version`.
- Existing `-wal`, `-shm`, `-journal` sidecars and known runtime markers cause a fail-closed denial. It uses read-only SQLite connections and produces a machine-readable eligibility receipt with **`restore_performed=false`, `runtime_stopped_verified=false`, `dpapi_identity_verified=false`, and `release_authorized=false`**. It never modifies/renames/replaces the active database, never copies the APP_KEY and never silently imports a different Windows DPAPI identity.
- `IIPersonalRestorePreflightTest` uses disposable SQLite snapshots to verify a correct SHA and schema, unmodified existing database and .env, and rejection of forged SHA, WAL sidecar, runtime marker, and an unregistered owner.
- **Important release gate:** this preflight is NOT a safe restore transaction. Actual recovery requires explicit verified application shutdown/exclusive SQLite lock, an independently validated and retained rollback copy, same-device APP_KEY/Windows DPAPI identity verification, schema/migration compatibility and atomic recovery before enabling a production restore command. Keep the production restore action disabled until those controls and clean-machine Windows acceptance are tested.
