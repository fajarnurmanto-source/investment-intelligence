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
