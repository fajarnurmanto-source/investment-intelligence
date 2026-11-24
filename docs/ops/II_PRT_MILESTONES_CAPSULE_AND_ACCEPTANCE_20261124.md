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
| M03 | IDX Edge PRO V13 source adapter and isolated tests | PARTIAL | PR #26 open; synthetic HTTP tests; original raw byte archival missing | exact-byte private archive + hash, provider live proof and tests |
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
- Gaps: source response SHA-256 currently hashes HTTP body but persisted wrapper is reserialized JSON, not exact raw bytes; no authenticated live receipt reviewed; period/currency rights and schema normalization remain unverified.
- Persist current gates: NOT_ADMITTED / HOLD / TRADING_AUTHORITY=NONE; no production market-value promotion.
- No installer, second-device acceptance, or cross-location sync is evidenced by this change.
 
## Follow-up implementation tickets
T01: Private byte-exact raw evidence sidecar and manifest; atomic write, failure rollback, content hash from persisted bytes, dedupe tests.
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
