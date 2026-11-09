# INVESTMENT INTELLIGENCE — MASTER PRODUCT ROADMAP SUCCESSOR FUSION

**Mandate date:** 7 November 2026; habitat amendment 9 November 2026
**Evidence refresh:** 9 November 2026
**Version:** `v0.2`
**Status:** `SUCCESSOR_FUSED / CANONICAL_BASE_PENDING`
**Repository:** `fajarnurmanto-source/investment-intelligence`
**Development branch:** `feature/ii-laravel-only-acceleration-20261107`

> This is a new versioned successor beside the frozen historical material. It does not overwrite, rename, or revoke any canonical/frozen roadmap. The ChatGPT Library artifact `II_MASTER_PRODUCT_ROADMAP_SUCCESSOR_FUSION_20261107.md` was retrieved on 9 November 2026 and its mandatory habitat amendment was fused here. A separately provable older canonical Master Roadmap still was not found, so base-canonical promotion remains pending while the retrieved successor governs current development.

## 1. Fusion basis and preserved evidence

The fusion preserves the following historical artifacts and assertions without upgrading their authority:

- 25 October successor draft: `PROVISIONAL / NOT_CANONICAL`; VS1–VS5 merge history; VS6 local status; frozen DR0–DR5 and D138 references.
- D50 checkpoint digest `e95fce1022472d8c4dc92976cd1c1ecbd24d1140b6c08f1adb16e9b1dae676bc`; state `AWAITING_HUMAN_SELECTION`; authority/admission `NONE / NOT_ADMITTED`.
- adjusted VS7–VS10 overlay: signals, fundamentals, strategy evaluation, portfolio intelligence, and productization sequence.
- local Laravel workspace evidence through VS11: authentication/roles, signals, fundamentals, strategy replay and validation, rolling validation, risk budget, portfolio analytics, source metadata, and responsive research UI.
- GitHub `development` head observed at `322620b22e5959a5ef5bf33767fd0791faa0b9b6` before this branch.

The commercial and governance invariants remain: `EVIDENCE=NOT_ADMITTED`, `DECISION=HOLD`, `AUTHORITY=NONE`, no broker execution, no production deployment, and no automatic data-rights promotion.

## 2. Architecture decision change

| Date | Previous decision | Successor decision | Reason and consequence |
|---|---|---|---|
| 25 Oct 2026 | Keep Python analytical engine and FastAPI contract; Laravel transactional shell | Superseded for active II runtime | The 7 Nov mandate requires Laravel as the only backend runtime. |
| 7 Nov 2026 | Laravel → FastAPI → Python B1 on port 8811 | Laravel Services/Controllers/Jobs/Queues/Scheduler/HTTP Client/Cache/Events/Eloquent/Authorization | Removes cross-runtime failure modes and one local service/port while preserving business behavior. |
| 7 Nov 2026 | Python source deleted after cutover | Python source retained as non-running rollback checkpoint | Rollback evidence is preserved; launcher, routes, CI, and active services contain no Python dependency. |
| 7 Nov 2026 | Synthetic/user-uploaded data only | Allow-listed FRED real-data pipeline for internal research | Adds real provenance, timestamps, quality state, retries, failure ledger, analytics, dashboard, and report without paid services. |

## 3. Preserved and advanced product milestones

| Milestone | Historical outcome | Fusion disposition |
|---|---|---|
| VS1–VS2 | Synthetic Company Research, B1 evaluator, history and provenance | Behavior ported to native Laravel services. |
| VS3 | Laravel/FastAPI bridge | Retired from active runtime; rollback source retained. |
| VS4–VS6 | Reviewer workspace, persistent cases, identity and roles | Preserved in Laravel and disconnected from port 8811. |
| VS7 | Signal Center and issuer fundamentals | Preserved; fundamental screener and CSV export added. |
| VS8 | Strategy replay, chronological holdout, rolling validation | Preserved as research-only Laravel services. |
| VS9–VS11 | Risk budget, portfolio analytics, data assurance, responsive UI | Preserved; historical VaR/CVaR and MathPHP statistics added. |
| L12 (new) | Laravel-only active backend | Implemented on isolated branch; final verification required before promotion. |
| D12 (new) | FRED source → Laravel ingestion → normalization → analytics → dashboard → CSV report | Implemented with provenance and failure handling. |
| B12 (new) | Benchmark-driven screener, portfolio tail risk, macro context | Implemented; global licensed market coverage and full valuation remain future work. |

## 4. Results-first roadmap

1. **Prove Laravel-only runtime:** migrate schemas; run feature/unit tests; assert no active port 8811, FastAPI, subprocess, or Python launcher references; exercise authenticated routes.
2. **Prove real-data flow:** ingest `CPIAUCSL` and `FEDFUNDS`; persist source/run/observation lineage; calculate headline analytics; render dashboard; export CSV report.
3. **Prove benchmark gaps:** screen imported issuer fundamentals; compute transparent score; export results; calculate portfolio historical VaR/CVaR and correlations.
4. **Promote only with evidence:** local commit is allowed; push/merge, production changes, paid data, destructive migrations, and legacy cleanup require separate approval.
5. **Next value after this branch:** add an explicitly licensed IDX/BEI issuer fundamentals adapter and normalized valuation model, then rule-based alerts. Do not infer redistribution rights from public visibility.

## 5. Acceptance boundary

Cutover may be reported complete only if all active routes and the launcher run without Python/FastAPI. Presence of preserved `.py` rollback files is not an active runtime dependency. Real-data completion requires successful observations in the Laravel database and a rendered/exportable user result. The named Library successor has now been retrieved and fused; promotion against a separately proven older canonical base remains pending.

## 6. Mandatory 9 November 2026 — canonical habitat and GitHub synchronization

**Library provenance:** `II_MASTER_PRODUCT_ROADMAP_SUCCESSOR_FUSION_20261107.md`, ChatGPT Library file `libfile_32068413c9c08191890fd08481ee7b71`, retrieved read-only on 9 November 2026. The retrieved document contains Sections XII through XII-D, which are adopted as the latest operational decision.

### 6.1 One local habitat

- Canonical habitat root: `D:\Research\INVESTMENT RESEARCH\II`.
- Only active Git worktree: `D:\Research\INVESTMENT RESEARCH\II\repository`.
- Source, migrations, tests, source assets, launchers, tools, and product documentation are tracked in that worktree.
- Environment, SQLite database, writable storage, cache, logs, sessions, report output, and installed dependencies live under `D:\Research\INVESTMENT RESEARCH\II\runtime\laravel` and are excluded from Git.
- Historical repositories, overlays, Downloads/Temp material, C-drive retirement candidates, and legacy Python sources are classified and retained. They are not independently editable active source.
- Ubuntu-24.04 may execute the D-mounted worktree, but it is not a second source of truth. The 9 November read-only inventory found no II repository under `/home`.

### 6.2 Push–pull contract

The repeatable `sync-II` mechanism must:

1. verify the canonical worktree and exact GitHub remote;
2. reject tracked secrets, runtime databases, installed dependencies, and logs;
3. fetch and prune remote refs;
4. perform pull only with `--ff-only` on a clean branch;
5. push only a feature/review branch and reject direct pushes to `main`, `master`, or `development`;
6. never force-push, auto-merge, deploy production, or upload runtime/backup data.

### 6.3 Consolidation acceptance

| Gate | Required evidence |
|---|---|
| `II_LARAVEL_CUTOVER` | Active routes, services, CI, launcher, queue, and scheduler have no Python/FastAPI/port 8811 requirement. |
| `II_SINGLE_LOCAL_HABITAT` | One worktree is used by launchers, synchronization tools, documentation, and operator commands. |
| `II_GITHUB_PUSH_PULL` | Feature branch is pushed; a subsequent clean fast-forward-only pull proves local/remote equality. |
| `II_FRAGMENTATION_RECONCILIATION` | Every scoped artifact is `TRACKED_SOURCE`, `LOCAL_RUNTIME`, `ARCHIVED`, or `PROTECTED`; no active-source candidate is `UNKNOWN`. |

### 6.4 Preserved restrictions

`PRODUCTION_ADMISSION=DENIED`, `C_CLEANUP_ADMISSION=DENIED`, and `NO_PAID_SERVICES_WITHOUT_APPROVAL` remain unchanged. R44/R49 and C-drive cleanup remain separate. No backup, rollback, dirty historical repository, or legacy Python source may be deleted by habitat consolidation.
