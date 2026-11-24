# II PRT M08 — Personal Installer Packaging Foundation

## Scope
This branch implements the **first executable Windows packaging gate**, not a finished installer.

- Read-only PowerShell preflight validates Laravel source, composer.lock, Caddyfile, launcher scripts, PHP CLI executable, FrankenPHP, PHP extensions, vendor autoload, built assets and CA bundle.
- Emits a JSON receipt with explicit status `INPUTS_READY_FOR_PACKAGING_REVIEW` or `BLOCKED_MISSING_INPUTS`; never prints or packages existing `.env`, APP_KEY, live SQLite, credentials or source archives.
- Synthetic Windows regression checks both complete and deliberately missing-php inputs. A separate GitHub Actions workflow runs these tests on PRs.
- Safe by default: this tool never copies private runtime, creates a distributable installer or modifies a device.

## Verified runtime assumptions
Existing launcher and deployment scripts use PHP from PATH, FrankenPHP in habitat runtime, and a pre-existing APP_KEY. A clean installation needs a device-specific secret and SQLite initialization. Those follow-up tasks require a dedicated first-run installer implementation, signing, and clean-VM testing.

## Acceptance gates
1. Green Windows CI for preflight.
2. Capture real preflight receipt in canonical local runtime (no secret values).
3. Verify redistribution rights and pinned hashes of packaged PHP/FrankenPHP/build dependencies.
4. Only then implement a separately staged payload builder, first-run owner onboarding, service lifecycle and signed installer.
5. Never merge or deploy by inference from synthetic test results.

Canonical product direction: [II PRT Master Roadmap](II_PRT_MASTER_ROADMAP_AND_MANDATORY_DECISION_20261124.md).

## M08-B incremental staged payload builder (24 November 2026)
- `tools/installer/Build-IIPersonalStagingPayload.ps1` stages only an explicit allowlist of Laravel app directories, Composer vendor autoload tree, built frontend assets, Caddyfile, PHP CLI/FrankenPHP binaries/extensions and CA root.
- An output folder must be new and separate from the source/runtime. The builder rejects symbolic links/reparse points, credentials and database files inside selected input locations. It does not bundle existing `.env`, personal SQLite, uploads, writable logs or market evidence.
- The staged package has `payload-manifest.json`, `payload-manifest.sha256`, per-file size and SHA-256 plus explicit `STAGED_UNSIGNED_NOT_INSTALLABLE` state. It is **not signed and not a Windows installer**.
- `tools/installer/tests/Test-IIPersonalStagingPayload.ps1` provides a synthetic complete payload with private fixtures, recomputes hashes, ensures sensitive files never ship, rejects overwriting the output, and tests an intentionally forbidden `.env` placed in selected input.
- Windows GitHub Actions invokes both preflight and staged payload tests. CI must PASS at the exact commit before considering staging code accepted. A full owner-runtime stage requires separate review of package licensing/redistribution and runtime portability.
- No first-run device initialization, migration, independent secrets generation, owner enrollment, service install, signed installer or clean VM acceptance is claimed here.

## Suggested read-only manual preflight (not deployment)
```powershell
& .\tools\installer\Test-IIPersonalPackageInputs.ps1 -RepositoryRoot "D:\Research\INVESTMENT RESEARCH\II\repository" -RuntimeRoot "D:\Research\INVESTMENT RESEARCH\II\runtime" -Strict
```
The script reports only presence/absence of input prerequisites. It does not read secrets, deploy, build or start services.

## M08 packaging implementation and milestone state — updated 24 Nov 2026
- **M08-A input inventory:** executable preflight and Windows synthetic acceptance PASS on PR #27, run [#4](https://github.com/fajarnurmanto-source/investment-intelligence/actions/runs/45382231626). Base Laravel CI run [#101](https://github.com/fajarnurmanto-source/investment-intelligence/actions/runs/45382231513) also PASS.
- **M08-B unsigned staging payload:** `Build-IIPersonalStagingPayload.ps1` copies only explicit code/runtime inputs into a new destination with per-file SHA-256 and manifest. Intentional status `STAGED_UNSIGNED_NOT_INSTALLABLE`. Excludes .env, active SQLite, personal storage, credentials and uploads. Cannot overwrite an existing package destination.
- **M08-B independent verification:** `Test-IIPersonalStagingIntegrity.ps1` checks manifest hash, all file hashes/sizes, exact file inventory and refuses undeclared files or unsafe paths. Tests inject tampering and extra files.
- Latest tests for verifier are subject to CI run #7 and general regression #104; **do not infer PASS before observing their completed outcomes**.
- **M08-C** device-local APP_KEY, SQLite, first-run enrollment and launcher, **M08-D** signed installer and update/rollback, **M08-E** actual two-device acceptance remain NOT_IMPLEMENTED.
- Packaging script produces a staging artifact, **not an installer**, and has not been run against owner's live Windows machine in this chat. Licensing and redistribution rights remain unverified.
