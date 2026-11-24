# II — PRT Milestones, Capsule State and Acceptance Matrix
Date: 2026-11-24
Document type: living operational state linked from the canonical PRT master roadmap.
Canonical policy: [II PRT Master Roadmap & Mandatory Decisions](II_PRT_MASTER_ROADMAP_AND_MANDATORY_DECISION_20261124.md)
This artifact does not replace the canonical roadmap.

## Single-owner product North Star
A private, noncommercial, Laravel-only investment research terminal that installs on multiple owner-owned computers in different locations, works locally when disconnected, acquires lawful market data, and provides reproducible research without automatic trading authority.

## Verified versus planned
Definitions:
- VERIFIED_IN_REPO: relevant code/tests or CI evidenced on GitHub; not proof of deployment or physical-device acceptance.
- PARTIAL: implementation exists but acceptance gap remains.
- PLANNED: not implemented/verified.
- BLOCKED: depends on authorization, licensing or unavailable data.
- PRODUCTION_ACCEPTED: only after documented local acceptance and explicit approval. Do not infer from CI.

| ID | Milestone | State | Evidence / limiting condition | Exit criteria |
| --- | --- | --- | --- | --- |
| M00 | Laravel-only core and local runtime | VERIFIED_IN_REPO, production behavior not revalidated | README describes FrankenPHP + Laravel local-only, queue, scheduler, private runtime | health and launcher on pinned deployment |
| M01 | Existing fundamentals, screener, VaR/CVaR, FRED | VERIFIED_IN_REPO / regression pending | existing documented features | non-regression on current release candidate |
| M02 | idxapi.io third-party source review | VERIFIED_IN_REPO / real data pending | PR #25 previously merged; keep provider independent | real evidence and provider rights reviewed |
| M03 | IDX Edge PRO V13 source adapter and isolated tests | PARTIAL — code implemented, acceptance pending | PR #26 now includes private `.raw` sidecar, SHA-256 verification and fixture byte-preservation tests; CI/Windows/live-provider acceptance still unverified | green CI, Windows-local test, authentic provider receipt and rights review |
| M04 | Real BEI fundamentals schema + normalization | BLOCKED | provider schema, currency/scale, filings and rights not accepted | schema and official FY period reconciliation; separate guarded normalizer |
| M05 | BEI market-price feed rights and access | PLANNED | no automatic real-time entitlement assumed | source-specific entitlement and controlled live pilot |
| M06 | US market data real-time limited pilot | PLANNED | investigate Alpaca IEX real-time and vendor non-display permission | bounded lawful feed, venue/time labels and regression |
| M07 | Source entitlement registry | PLANNED | display/non-display/retention/export/device/sync distinction required | deny-by-default capabilities and automated negative tests |
| M08 | Windows installer MVP | PLANNED | existing start/restart scripts are not installer | clean OS install, launcher, health, upgrades and rollback |
| M09 | Owner-owned second device acceptance | PLANNED | no separate device validation yet | distinct secrets/device identity/SQLite; cross-location offline smoke |
| M10 | Rights-aware owner-only sync | PLANNED, optional | must not copy licensed data or active SQLite | encryption, revocation, offline conflict merge and data filters |
| M11 | Stable personal release | PLANNED | multiple prior gates outstanding | release manifest, signed build, recovery, UX and security acceptance |

## Roadmap dependency order (not strict feature-stop)
P0: stabilize latest candidate, isolate production, run targeted/full regression; retain V11 production baseline until accepted.
P1: source rights registry; IDX Edge raw-byte evidence closure, guarded provider live test and FY reporting reconciliation; pilot lawful BEI and US feeds.
P2: package existing local Laravel/FrankenPHP runtime into Windows installer with supervised services, independent SQLite and proper APP_KEY management.
P3: device identity + revocation, separate installs, optional rights-aware synchronization, no active DB copying.
P4: progressively improve existing screener, valuation, risk, portfolio, research alerts and UI using admitted data only.
P5: signed releases, update/rollback, backup/restore, observability, multi-device recovery.
Parallelism: P1 provider research and P2 installer discovery may proceed together without granting numeric admission; P4 regression continues throughout.

## Mandatory decision mapping
The canonical list in II_PRT_MASTER_ROADMAP_AND_MANDATORY_DECISION_20261124.md is authoritative. Additional operating tests:
- One owner does not imply shared vendor entitlements; require explicit per-provider multiple-device and non-display rights.
- No production trading, no unauthorized scraping, no false consolidated real-time labels, no direct live-feed import to screener.
- Bundle dependencies only within their own redistributable software licenses; a software installer must not embed market data subscriptions or API keys.
- Each installation owns local APP_KEY/secrets and database; recovery uses explicit backup/restore instead of copying active SQLite.
- Separate technical acceptance from vendor licensing acceptance and owner deployment approval.

## V13 capsule state — known as of this document
- Repository: fajarnurmanto-source/investment-intelligence
- Active PR: #26; branch feature/ii-v13-idxedge-free-pilot-20261124; not merged at assessment.
- Previously assessed PR baseline: 72ea57cc4992f90dbbdd1ef429fad001e5361f50; GitHub CI run 45350716802 success at that revision.
- Subsequent documentation commits: 33359a104159adb175d863e6eadd739da6a6f1cc and 08edbafeb5cbe335e3e0d8a636efb8bb3fcccb53. Newly amended HEAD requires separate CI verification.
- V13 adapter source: laravel_app/app/Services/IdxEdgeFinancialEvidenceService.php
- Pilot command: php artisan ii:idxedge:pilot; default BBCA/TLKM/ASII; private Laravel local storage
- Update (24 Nov): code now passes exact upstream bytes internally to a private `.raw` sidecar, checks the stored SHA-256 and keeps raw bytes out of UI review JSON. Synthetic tests include whitespace-preservation assertions. **At this writing no CI or Windows acceptance at the newest commit has been observed.** Still no authenticated provider-live receipt, period/currency/rights reconciliation or accepted numeric schema normalizer.
- Persist current gates: NOT_ADMITTED / HOLD / TRADING_AUTHORITY=NONE; no production market-value promotion.
- No installer, second-device acceptance, or cross-location sync is evidenced by this change.
 
## Follow-up implementation tickets
T01: Private byte-exact raw evidence sidecar + manifest, stored-byte hash verification, duplicate scenario and fixture regression **implemented in PR #26, pending CI/Windows QA**. Legacy manifest backfill, simulated disk-write failures and strict atomicity remain acceptance follow-ups.
T02: Provider-authenticated test (secrets local only), real raw schema map, rights review and official filing reconciliation.
T03: Source entitlement/capability matrix implementation (per source, plan, device, usage, date).
T04: Windows runtime packaging spike: installer technology, Laravel/FrankenPHP binaries/license, boot process, service recovery, ports, migrations, encrypted app key, uninstaller safety.
T05: Clean machine install + second-device independent offline test and QA receipts.
T06: Rights-aware synchronization protocol design, with no licensed-data movement by default.
T07: Regression, release signing, rollback and recovery exercise.

## Acceptance receipt template
- repo + exact commit SHA
- CI run URL/status/test counts
- Windows-local runtime command/results
- installer build SHA and signature verification
- device A/B IDs (non-sensitive pseudonyms), OS versions, isolated database evidence
- source provider/plan/exchange/venue, permissions for display/non-display/retention/sync, rights-review evidence
- timestamp source/latency classification (LIVE_VENUE_ONLY, LIVE_CONSOLIDATED, DELAYED, EOD, UNKNOWN)
- exact raw file path (private), stored bytes hash and cryptographic integrity test result
- official filing reconciliation fields + exceptions
- numeric admission decision / trading authority
- rollback and recovery evidence
- explicit owner approval where required

No CI, deployment, live data, rights approval or installer acceptance is asserted solely from writing this state document.

## Incremental acceptance update — 24 November 2026
- CI evidence: GitHub Actions run `45376739761`, result SUCCESS for prior HEAD `ff23d2d6bbff0ddfb423dc6c9468d0e1fd1ad322`; Linux Laravel and Windows hosted isolated Tinker/PHPUnit jobs both succeeded. This is **CI-runner Windows**, not owner-machine acceptance.
- Follow-up code: fail-closed legacy manifest and tampered raw archive handling, avoid deleting pre-existing raw evidence during manifest failure, plus two new synthetic feature tests. New HEAD must earn its own CI success.
- Open: deliberately simulated storage-write failures, provider-live receipt, license/rights review, original issuer filing reconciliation, owner Windows machine smoke, installer MVP.
- Release gate unchanged: `NOT_ADMITTED / HOLD / TRADING_AUTHORITY=NONE`, no merge/deploy.

## Acceptance continuation — write-failure simulation
- GitHub Actions run 45377310529 (workflow #85) reported SUCCESS for 7cbfd8085ad92242a3c58eb208f5e83f1e2cbda8, with Linux and Windows-hosted jobs PASS.
- Later commits introduce two isolated synthetic storage failure tests (raw `.raw` write and manifest `.json` write) and cleanup logic for partial writes. **These new commits are not covered by run #85**; CI on the resulting HEAD must be verified independently.
- The current archive sequence is not a multi-file transactional filesystem guarantee; abrupt process termination and concurrency remain hazards to address with temp files, atomic rename/locking and recovery. No claim of physical disk-full test.
- Source-live, legal rights, owner Windows-local, and financial-admission gates remain pending; no production deployment or merge.

## V13 lock and M08 installer audit — 24 Nov 2026
- Latest previously verified CI: GitHub Actions #88 (run 45379031038) on `e26d76352ee027f743d137b0d1a861af14d9e63d`; Linux and Windows hosted CI succeeded.
- New V13 work on feature branch: checksum-scoped local advisory `flock` to serialize archives, plus lock-contention negative test; no latest-head CI success was available when this update was written.
- Atomicity qualification: advisory locking prevents cooperating process interleaving, but existing direct `Storage::put` writes are **not atomic rename** and abrupt-process recovery is not yet proven. Must implement temp-file + same-filesystem rename and recovery scanner with dedicated tests before promoting M03 acceptance.
- [M08 Windows installer runtime audit](II_PRT_WINDOWS_INSTALLER_M08_RUNTIME_AUDIT.md): reviewed existing FrankenPHP/local launcher, PHP dependency, queue/scheduler, deployment/recovery scripts; specifies clean machine, per-device APP_KEY/SQLite, signed bundles, rollback and second-device test requirements. No installer has been shipped.
- Live-provider and rights checks remain pending; commercial redistribution rights for bundled runtime must be checked. `NOT_ADMITTED / HOLD / TRADING_AUTHORITY=NONE` unchanged.

## V13 staged evidence milestone — 24 Nov 2026
- PR #26 implements same-directory staged `.pending-*` files with hash verification, rename via local Laravel filesystem, checksum-scoped file locking and scoped orphan staging cleanup. This closes a coding gap, **not** full power-loss durability or provider-live acceptance.
- Added synthetic test for abandoned staging recovery and revised mocked staging-write failures. Current code CI is GitHub Actions run #95; do not label it PASS until completed and verified. Windows owner-device acceptance is still open.
- M08 installer design audit remains separate from implemented installer deliverable; clean-device packaging has not started.
- Financial data admission remains `NOT_ADMITTED / HOLD / TRADING_AUTHORITY=NONE`; live FY report validation and rights verification pending.
