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
