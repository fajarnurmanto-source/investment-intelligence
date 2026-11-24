# II — Personal Research Terminal (PRT): Mandatory Decision + Master Roadmap
Date: 2026-11-24
Decision status: OWNER-DIRECTED. Planning and policy baseline; not a production deployment.
Repository context: V13 IDX Edge PRO PR #26.
 
## Governing product decision
- II is a **single-owner, non-commercial Personal Research Terminal**, not a public-facing or multi-tenant SaaS product.
- II SHALL be installable by the same individual on multiple personally owned devices in different physical locations.
- This does NOT imply that a single market-data entitlement is transferable across devices, locations, simultaneous sessions, algorithms, or accounts.
- A future installer shall be owner-scoped, signed, versioned and reproducible; personal installation is not a data redistribution license.
- Monetization, SaaS billing, customer tenant management and vendor redistribution are removed from near-term release gates, not implicitly permitted or permanently deleted from the architecture.

## Mandatory decisions — binding guardrails
1. RESULTS-FIRST — demonstrate installed, observable capabilities and real-world acceptance.
2. REUSE-FIRST — leverage native Laravel, Composer and maintained licensed packages.
3. REAL-DATA-FIRST — source evidence, point-in-time timestamp, provenance, validity and rights; never substitute fixtures for real data.
4. LARAVEL-ONLY — canonical backend and domain logic in Laravel; packaging/OS helpers may exist but are not alternative domain runtimes.
5. SINGLE MASTER ROADMAP — PRT, connectors, data licensing and installer are streams of the same II product.
6. BENCHMARK-DRIVEN — benchmark useful capabilities without unauthorized replication or third-party data redistribution.
7. AUTOMATED-MINIMUM-QA — Linux CI, Windows-local isolated smoke, targeted/full regression, signed installer QA, migration/rollback tests.
8. SINGLE-OWNER MULTI-DEVICE — separately registered installations and device revocation; never share session cookies, SQLite database file, APP_KEY or local secrets by copying them between active devices.
9. LICENSE-AND-ENTITLEMENT-FIRST — provider, exchange, plan, account and device-specific rights determine whether display, non-display, persistence, export, copying, sync, archival and automated analysis are permitted; classify independently. Fail-closed for UNKNOWN.
10. REALTIME-IS-SOURCE-SCOPED — mark IEX-only vs consolidated SIP distinctly; real-time vs delayed/EOD, coverage, venue, session and timestamp must be visible; never label an incomplete feed as whole-market real-time.
11. NO-UNAUTHORIZED-SCRAPING — no exchange website scraping, reverse-engineered mobile endpoints, bypass of provider account limits, session duplication or credential sharing.
12. NO-AUTOMATED-TRADING-AUTHORITY — NOT_ADMITTED/HOLD/TRADING_AUTHORITY=NONE until separate explicit review; portfolio analytics do not authorize broker execution.
13. SAFE RELEASE GATES — do not merge/deploy, mutate production DB, rotate APP_KEY, publish credentials, or relax provider rights based only on CI or user checklist.
14. PRIVACY-AND-SECURITY-BY-DESIGN — encrypted secrets per device, local-only default, least-privilege auth, secure updates, integrity checks, backup and recovery.
 
## Market data entitlement decision matrix
Every source must carry:
- provider, underlying exchange/venue, instrument/market, API contract/version, access authorization proof, account owner and plan
- real-time/delayed/EOD; coverage scope (venue-only vs consolidated); timezone and capture timestamp
- display permission, non-display algorithmic permission, retention/archive, derived-data calculation, export, synchronization between own devices, simultaneous sessions, redistribution restrictions
- subscription/device limits, source attribution, rate limits, effective date, last reviewed and evidence URL/document
Rights decision is per capability: ALLOWED, DENIED, UNKNOWN. UNKNOWN -> HOLD.
Do not assume personal/non-professional display rights also grant algorithmic non-display rights.
 
## Target topology
A. Primary release: Windows personal installer for Laravel runtime, bundled/static supported assets, supervised service, native launcher, SQLite local workspace, queue/scheduler, health-check, log viewer and uninstall/data-retention controls.
B. Every device has its own device_id, installation keys, SQLite database and encrypted runtime secrets. Single-user identity is the canonical owner; device registration/revocation is separate from external provider identity.
C. Start with **independent offline-capable installations**. No automatic full-database file copy or shared network SQLite.
D. Optional owner-only sync via authenticated encrypted transport / VPN or dedicated sync endpoint, explicit conflict strategy, immutable evidence IDs, per-record ownership and provider-specific sync entitlements. Keep licensed raw market feeds local if rights do not permit transfer.
E. Centralized *single fetcher* is optional only if provider terms permit remote display and remote non-display use on other devices; device count and physical access remain enforceable.
F. Prefer per-device provider credentials or vendor-supported credential entitlements; do not silently share subscriptions.
G. Signed installer with reproducible release manifest, offline upgrade/rollback, migration backups, startup service checks and remote-device health status where approved.
 
## Revised master roadmap
P0 — BASELINE STABILIZATION: preserve V11/V13 state, audit runtime, QA artifacts, secrets, backup/restore, no unauthorized data admission.
P1 — REAL DATA AND RIGHTS MATRIX: continue IDX Edge PRO fundamental raw-byte evidence + checksum closure, licensed BEI EOD/delayed/live candidate assessment, Alpaca IEX limited US real-time pilot, entitlement registry + source-specific rights gate.
P2 — PERSONAL INSTALLER MVP: Windows installer with launcher, bundled Laravel server service, config, isolated SQLite, device registration, signed update and local acceptance on fresh machine. First single-device installation, then second-device clean install.
P3 — MULTI-DEVICE OPERATIONS: independent installations, encrypted backup, device registry/revocation, optional owner-only sync with rights-aware payload filtering and conflict resolution; test two devices across distinct networks.
P4 — RESEARCH INTELLIGENCE: evidence-led screener, valuation, risk, portfolio tracking, watchlist, real-time indicators only where non-display rights allow, market-session/currency/venue-aware controls.
P5 — RELIABILITY AND DISTRIBUTION: upgrade/rollback, offline resilience, provider outages, observability, release signing, security audit and recovery drills. Public distribution and commercial licensing remain out of scope.
 
## Phased acceptance
Gate 0: Repo CI + isolated Windows tests PASS; production data unchanged.
Gate 1: Source-specific legal/contract entitlement reviewed (BEI, IEX/Alpaca, any global provider), including non-display and multi-device usage.
Gate 2: Live-data raw-byte archival hash verified and canonical data ingestion remains denied until independently reconciled.
Gate 3: Windows installer runs on new device without manual developer terminal; login, scheduler, local DB migrations, restart and health check pass.
Gate 4: Second owner-owned device installs independently; separate secrets/DB; no accidental shared sessions; both work offline.
Gate 5: Cross-network sync (only if permitted) passes auth, conflict, revocation, encrypted transport and licensed-data filtering tests.
Gate 6: Signed updates, rollback and backup/restore validated without losing provenance or evidence.
 
## Research sources to re-review at time of vendor activation
- IDX data services: https://www.idx.co.id/id/produk/layanan-data-bei
- IDX terms: https://www.idx.co.id/id/syarat-penggunaan
- NYSE policies (non-display and multiple installation): https://www.nyse.com/market-data/policies
- Nasdaq non-display clarifications: https://www.nasdaqtrader.com/TraderNews.aspx?id=dn2015-09
- Alpaca Market Data: https://docs.alpaca.markets/us/docs/about-market-data-api
Provider plans and rights change. This decision log is not itself a legal license or vendor authorization.
