# INVESTMENT INTELLIGENCE — MASTER PRODUCT ROADMAP SUCCESSOR FUSION

**Version:** `v0.3`

**Effective:** 10 November 2026

**Status:** `SUCCESSOR_FUSED / LOCAL_PRODUCTION_OPERATIONAL / CANONICAL_BASE_PENDING`

This version succeeds v0.2 without altering frozen historical roadmaps. It preserves VS1–VS11 milestone evidence, the 7 November Laravel-only architecture decision, the 9 November single-habitat decision, and the unresolved absence of a separately provable pre-successor canonical base.

## Decision update

The owner authorized local production deployment and verified legacy retirement on 10 November 2026. This supersedes the v0.2 local-production and cleanup denial for this bounded operation only. It does not authorize external production, paid services, billing, automatic trading, destructive database migration, force-push, or unreviewed main-branch merge.

## Achieved state

### 10 November 2026 — Issuer Fundamental Intelligence V1 development checkpoint

- Added Laravel source registration, immutable document versions, normalized historical periods, SHA-256 provenance, license/usage-right status, and data-quality gates.
- Added lawful user-supplied CSV, XBRL/XML, and PDF intake; no IDX scraping, hidden API, paid service, or production database mutation.
- Added nullable P/E, P/BV, EV/EBITDA, ROE, debt/equity, revenue/earnings growth, OCF, historical comparison, exports, and screener integration while retaining `NOT_ADMITTED`, `HOLD`, and `TRADING_AUTHORITY=NONE`.
- Verified in an isolated database with PT Telkom Indonesia (Persero) Tbk's official audited Annual Report 2024 disclosure, classified as internal-research/no-redistribution.
- Deployment remains a separate approved action after PR review; local production remains on the prior `development` baseline.

### PR #8 acceptance correction

- Reconciled Telkom FY2024/FY2023 against the official Annual Report: `total_debt` now includes lease liabilities (`76,868`/`68,124` Rp billion); the earlier `52,909`/`47,699` subset was debt excluding leases. FY2024 EBITDA was corrected from `75,579` to `75,029` Rp billion.
- Separated completeness scoring from source authenticity, numeric reconciliation, licensing assessment, and provenance confidence. Completeness never changes `NOT_ADMITTED`, `HOLD`, or `TRADING_AUTHORITY=NONE`.
- Hardened XBRL intake for supported IFRS namespaces, entity/context/period/dimension isolation, currency/share units, decimals/precision, duplicate/contradictory facts, nil facts, and external-entity/network rejection. Unsupported taxonomy/dimension cases are archived on hold without numeric output.
- Public CSV examples are synthetic. Market valuation requires an explicit price as-of date; null and zero remain distinct and CSV formula-injection defenses apply to all exported text cells.

### 10 November 2026 — Issuer Fundamental Intelligence V1 production acceptance

- PR #8 merged into `development` at `c048e6c78c27eb4661e0a7f3faa086d33ad64c8f` after correction commit `ce82972cd878d0de60446f9b832f233476d42795` and successful GitHub CI.
- Local production was migrated additively with a verified recovery point, preserved APP_KEY/SQLite/storage, and remained localhost-only on FrankenPHP/Caddy with queue and scheduler.
- The authenticated login → dashboard → FRED → CSV journey passed with 1,824 observations. One reconciled Telkom document with FY2023/FY2024 periods was separately imported into production; valuation remained `N/A` because no rights-cleared dated market price was supplied.
- SQLite integrity, Laravel-only runtime, source/runtime commit alignment, and `NOT_ADMITTED`/`HOLD`/`NONE` gates passed final acceptance.

### 10 November 2026 — Multi-Issuer Fundamental Intelligence V2 development

- Started V2 on a feature branch from the accepted V1 merge without modifying local production.
- Added Laravel queue-based batch manifests, per-item results and retry, issuer sector/period classification, explicit source conflict retention, auditable IFRS taxonomy registry output, and separately provenanced dated market-price observations.
- Added cross-issuer sector/period filters, ascending/descending ratio sorting, provenance/conflict indicators, and expanded CSV output.
- The development acceptance set uses official Telkom, Astra, and Unilever Indonesia FY2024 publications for internal research only; source PDFs and redistributable datasets are not committed.
- Isolated real-data batch acceptance completed 3/3 items (three documents, six periods, three screener rows) with independent SHA-256/source records. No rights-cleared dated market price was asserted, so every valuation ratio remained explicitly `N/A`; V2 was not deployed and production V1 remained unchanged.

- Laravel is the sole active backend runtime.
- Local production uses FrankenPHP/Caddy on localhost with queue and scheduler workers.
- APP_KEY, SQLite/FRED data, `.env`, and storage remain external to Git and were recovered into a verified checkpoint before deployment.
- The authenticated FRED journey and CSV reporting passed with 1,824 observations.
- Python/FastAPI source and hybrid overlays were removed from the active branch while remaining recoverable through history.
- One canonical habitat remains: `D:\Research\INVESTMENT RESEARCH\II`.
- Historical repositories and C/D fragments were removed from active paths and placed in a hash-verified recovery quarantine where permanent deletion was not admitted by the safety gate.

## Preserved governance

`EVIDENCE=NOT_ADMITTED`, `DECISION=HOLD`, and `AUTHORITY=NONE` remain the default for unqualified data. Public availability does not grant redistribution rights. FRED is the only connected real-data source in the current acceptance evidence; IDX/BEI, BI, and OJK connectors remain future rights-aware work.

## Next highest-value action

Add an explicitly licensed IDX/BEI issuer-fundamentals adapter with provenance, normalization, valuation outputs, failure handling, and the same `NOT_ADMITTED`/`HOLD` safeguards. This is the next product capability; further habitat restructuring is not required.
