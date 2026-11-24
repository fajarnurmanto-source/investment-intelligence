# II — Local BYOK Source & Transformation Lineage Registry

Date: 24 November 2026
PR: #28 (draft)
Status: code implemented on feature branch; acceptance depends on exact-HEAD CI.

## Data model

- `ii_lineage_nodes`: immutable-by-service local records, with owner, type `SOURCE` / `TRANSFORMATION`, reference type and ID, operation code, source SHA-256/output SHA-256, deterministic claim fingerprint, rights profile, rights basis, restrictive effective capabilities and sanitized metadata.
- `ii_lineage_edges`: owner-scoped directed parent → child connections with uniqueness and foreign keys. A transformation requires at least one existing parent of the **same owner**; parent IDs are sorted for deterministic fingerprints.
- No API key, provider JSON body, quote value, raw CSV contents, query-string token or secret is intentionally copied into lineage metadata. Evidence hashes are identifiers, not permission or proofs of accuracy.
- `LocalLineageRegistry::registerSource`: source-claim recording; explicit `RESTRICTED_BYOK` for known MarketData.app BYOK metadata; other declared sources are `USER_ATTESTED`, **not contract-approved**. No positive export or cloud capability granted at this stage.
- `LocalLineageRegistry::registerTransformation`: output claim tied to all parent IDs; effective permissions are an intersection of parent permissions. Restrictive BYOK rights propagate transitively; transformations cannot grant export/cloud transfer.
- `find`, `allows`, `trace` provide owner-scoped inspection of derived rights and bounded ancestor graph; no public HTTP lineage endpoint or market-data distribution.
- Application-level append-only service plus reference uniqueness make conflicting writes fail. **Not a tamper-evident ledger against arbitrary SQLite administrators**; no signature, hardware attestation or anti-tampering claim.

## Existing workflow integration

1. **Fundamental import:** `IssuerFundamentalImportService` registers the uploaded source fingerprint and normalized issuer-period transform **within the same database transaction as the document/period inserts**. If lineage registration fails, the transaction rolls back and the uploaded file is cleaned up by existing error handling.
2. **Manual/batch price import:** `IssuerMarketPriceService` registers source metadata with the hashed observation in a transaction, nested safely inside atomic batch imports. Restricted BYOK provider records are blocked **before persistence**.
3. **Snapshot consumption:** `FundamentalSnapshotService` attaches `lineage_node_id`, `lineage_market_price_node_id`, `lineage_status`, `lineage_rights_profile` and `lineage_effective_export` to newer imported-document snapshots. When both parent inputs are registered, status is `INPUTS_REGISTERED_TRANSFORM_PENDING`: the price-dependent valuation calculation is explicitly **not yet recorded as a transform**. Pre-registry older documents are **UNREGISTERED** and never silently certified.
4. **Exports:** `BlockRestrictedByokExports` now blocks known restricted ancestry recorded in the registry, even if mutable legacy source display metadata is later edited; earlier metadata-based price/document checks remain in place.
5. **MarketData live quote:** remains transient *in-app only*, with `raw_archive=false` and `storage=false`; **no quote payload, numeric quote hash or vendor snapshot is recorded in persistent registry**. A source catalog record with no provider payload is a separate metadata concept, not an entitlement.

## Coverage and limitations

Current operational edges include: imported fundamental source → normalized periods; market price imports are registered as **source nodes**. Dynamic valuation/portfolio/strategy outputs, background job integrations, macro analytics and external report formats do not yet have a complete per-field transformation DAG. A snapshot's normalization node is not proof that every computed ratio has its own immutable transformation record.

No market-data redistribution permission is inferred from user-attested `licensed_internal`, source origin metadata or the mere presence of an API key. Existing research-only exports retain their previous behavior for independent data; restricted BYOK exports are blocked. A later release should add audited, granular export authorization across all outputs rather than treating `USER_ATTESTED` as a legal license.

## Tests

`LocalLineageRegistryTest` validates idempotent registration, conflicting SHA-256 rejection, owner isolation, multi-parent restrictions, inherited no-export/no-cloud rules, bounded ancestry, correct price/document import integration, and rejection of restricted legacy market sources. Existing BYOK and Laravel regression plus Windows packaging are mandatory.

Do not merge PR #28, migrate an owner's production SQLite, call an actual provider or distribute installer until CI and future clean-machine acceptance are verified.

## Operational data-lineage boundaries

- `LocalLineageRegistry::trace(userId,nodeId)` provides a bounded owner-only ancestry graph for review without revealing numeric input or credentials. Foreign-owner nodes fail closed.
- A known `RESTRICTED_BYOK` rights claim in the registry blocks issuer/screener CSV through `BlockRestrictedByokExports`, including cases where somebody later changes the mutable source name or URL in legacy records.
- The rights registry is not a retroactive source classifier for *all* historical documents; old records without a node remain `UNREGISTERED` and require explicit backfill/audit before asserting graph completeness.
- Immutable source fingerprint and transformation hash bind claims at creation; **the initial registry does not independently re-hash files/DB periods on every subsequent read or prevent a privileged SQLite administrator from changing rows**. Do not market it as tamper-proof.
- Live MarketData BYOK quotes are intentionally never registered as stored numeric payloads. No market-data provider legal rights are invented from a user key or unverified user claim.

## V2 — Derived valuation and screener lineage (24 November 2026)

- Added `DerivedAnalyticsLineageService`. A research valuation now registers `DERIVE_ISSUER_VALUATION_V1` with explicit parent of `NORMALIZE_ISSUER_PERIODS_V1` plus `ACQUIRED_SOURCE` price observation when selected. Output is bound by SHA-256 of a controlled numeric projection. Only the digest, operation version, parent UUIDs and rights metadata enter SQLite lineage tables; **no numeric values, raw vendor response or API key are copied** into registry metadata.
- Added `SCORE_FUNDAMENTAL_SCREEN_V1` with the valuation transform as parent and deterministic hash of score/reasons/filter inputs, connected in `FundamentalScreenerController` after scoring. An owner-only registry lookup checks that the proposed parent is truly a nonrestricted valuation transformation, not another user's UUID or arbitrary node.
- Recalculation from changed prices, reconciliation or scores receives a new content-addressed reference rather than mutating previous evidence nodes. Repeated identical calculations are idempotent.
- Restrictions remain inherited transitively: both output types have `can_export=false` and `can_cloud_transfer=false`; recognized BYOK restrictions and missing/contested input identities are not persisted as numeric derived outputs.
- `FundamentalSnapshotService` attaches `lineage_valuation_node_id` and `lineage_valuation_status`. This is an audit claim about the calculated projection observed on the local runtime, **not** permission to export, financial accuracy certification, or proof all share-basis review inputs were independently traced.
- New `DerivedAnalyticsLineageTest` verifies two-source → valuation → screener ancestry, idempotent replay, changed output new version, no raw prices in metadata, multi-user isolation, restriction denial and missing-price fail-closed. Existing import test also checks real HTTP-sourced document and price produce expected nodes and screener action registration.
- Known limitations: on-read materialization of computed metadata creates append-only SQLite lineage records when the fundamentals or screener page runs; care is needed with large datasets and local disk. Financial review/share-basis documents are not yet separate parent nodes. Old nonregistered records remain `PENDING_INPUT_REVIEW`, not silently backfilled. MarketData BYOK live feed stays volatile and unarchived.

## V3 — Share-basis evidence ancestry and on-read integrity (24 November 2026)

- Every newly recorded corporate action receives an immutable-by-service `issuer_corporate_action / ACQUIRED_SOURCE` node; every accepted share-basis review receives an `issuer_share_basis_review / ACQUIRED_SOURCE` node. These registrations are in the **same SQLite write transaction** as their domain records, without raw factor or share-count values in registry metadata.
- The `RECONCILE_SHARE_BASIS_V1` transform now explicitly depends on the review source and all in-period action source nodes. If a source action has no lineage node or mismatches its evidence SHA, the transaction refuses the new review; old unregistered action history requires separate audited backfill. This is a fail-closed cutover, not silent reconstruction.
- `resolve()` returns the successful share-basis review ID; `FundamentalSnapshotService` attaches `lineage_share_basis_node_id` from the reviewed transformation. `DerivedAnalyticsLineageService` requires this parent for a valuation with `ALIGNED_USER_ATTESTED`; missing review lineage produces `PENDING_INPUT_REVIEW`, not a certified valuation node.
- Added `LocalLineageRegistry::verifyTrace`: recalculates stored node-claim fingerprints across owner-scoped ancestors; rejects missing/changed edges, mutated permissions without matching fingerprint, restricted-rights widening and cross-account roots. The valuation and screener admission boundaries call it before registration of new derived lineage nodes.
- Tests were extended to verify corporate-action → review → valuation ancestry, lineage ownership and tampered-rights detection. Changing a parent identity now returns a non-admitted `FAILED_PARENT_INTEGRITY` state rather than accepting a forged ID.
- **Limits:** database administrators who can rewrite the stored claim and its fingerprint can still forge this local metadata ledger; external signatures/attestation and read-time source-file re-hashing remain future work. Successful CI does not prove contractual data entitlement, legal non-display permission or actual signed Windows deployment.

## V4 — Signal, portfolio, strategy and export-integrity boundary (24 November 2026)

- **Signal Center restriction:** the authenticated CSV intake now applies the same `ByokIntakeFirewall` as market-price and issuer imports before opening/uploading the file. `SignalInsightService::save()` also enforces it for direct callers. Historical JSON series with known restricted MarketData.app provenance are quarantined **before** signal, portfolio or strategy calculations.
- **File-level binding:** each newly saved owner-local OHLCV JSON source gets an `ACQUIRED_SOURCE / signal_ohlcv_document` lineage node with SHA-256 of the exact stored bytes. Read-time recomputation plus `LocalLineageRegistry::verifyTrace()` attaches only valid matching source IDs. Legacy/unregistered or altered JSON has `UNREGISTERED` status, never positive lineage clearance. On source-node registration failure, only the just-created JSON file is deleted; prior imports remain untouched.
- **Derived analytics:** `SignalAnalyticsLineageService` connects two or three independent OHLCV source IDs to `ANALYZE_PORTFOLIO_V1`; `REPLAY_STRATEGY_V1` uses one verified owner-local OHLCV source. Algorithm settings/output projections are hashed for versioned deterministic output identity; actual numbers and vendor payloads are not stored inside the registry. Records with missing source lineage or parent ownership fail closed as `PENDING_SOURCE_LINEAGE`, not falsely certified. No order execution or trading authority.
- **CSV integrity audit:** `BlockRestrictedByokExports` now verifies issuer/fundamental/screener lineage claim fingerprints and inherited rights before generating their CSV. A corrupted independent-source node blocks the current owner's CSV as `II_LINEAGE_EXPORT_INTEGRITY_FAILED`; another user's unrelated data remain isolated. A hard 500-node synchronous limit blocks export pending separate batch audit. The prior restricted-BYOK block remains in place.
- **Tests:** `SignalAnalyticsLineageTest` covers source SHA/file integrity, 2-parent portfolio DAG, 1-parent strategy DAG, repeatable transform IDs, cross-user isolation, rejected BYOK uploads and pre-cutover restricted JSON. `LocalLineageRegistryTest` adds tampered-node export rejection and independent-user isolation.
- **Explicit limitations:** this code does **not** grant legal export rights to independent or user-attested data. Existing independent-source CSV workflows are retained subject to their existing controls; default registry `can_export=false` remains deliberately conservative. Signal history predating V4 remains readable for permitted sources but not lineage-certified; unregistered historical data need reviewed backfill. A user able to rewrite both SQLite and hashes, or deliberately misrepresent a provider, is beyond this metadata-only tamper-detection scope. Real Windows installer, vendor-live acceptance and secure update/recovery remain outstanding.

## V5 — Synthetic research-report attestation (24 November 2026)

- `SyntheticReportLineageService` verifies `GENERATED_DEMO`, `NOT_ADMITTED`, `HOLD`, `authority=NONE`, no valuation/trading authorization and absence of known restricted-provider provenance before report generation is certified. This is explicitly **not an export authorization for external data**.
- The service creates a metadata-only `synthetic_fixture / ACQUIRED_SOURCE` node versioned by SHA-256 of the fixture, and a child `synthetic_report / RENDER_SYNTHETIC_REPORT_V1` transform bound to SHA-256 of the **exact rendered Markdown**. The report text, synthetic numeric rows, reviewer notes and API secrets are never copied into registry metadata.
- `ResearchWorkspaceController::report` and `ResearchCaseController::report` now attest their rendered synthetic output **before returning the file** and include `X-II-Lineage-Node`, `X-II-Lineage-Status: VERIFIED_SYNTHETIC_RESEARCH_ONLY`, and `X-II-Content-SHA256` response headers. Changing a reviewer note produces a distinct immutable report node; identical content is idempotent.
- The registry remains `can_export=false` for these nodes because no general-purpose data distribution license is granted. The pre-existing synthetic-only download endpoints are exceptional demo flows, not evidence that user-provided or provider data is cleared to export. External market-data report flows must use separate affirmative rights authorization (still not implemented).
- Tests in `SyntheticReportLineageTest` cover content-hash agreement, 2-node ancestry, owner isolation, repeatability, session note versioning, cases, rejection of disguised live/restricted content, and failure before download if a registered source fingerprint is corrupted.
- Other reports are deliberately not mislabeled as synthetic: the independent-source FRED macro CSV and SEC normalization workflows retain their existing distinct controls. Their future rights-aware output manifests and source-level export permissions remain an open acceptance gate.

## V6 — Rights-aware independent FRED macro reporting (25 November 2026)

- Added `MacroReportRightsGate`: authenticated macro CSV export now performs a **fail-closed series-level policy preflight**. The existing local FRED analytics dashboard remains accessible independently of export clearance.
- `/ii/macro/report/rights` returns a data-minimized JSON manifest: series ID, source owner, official FRED host, latest response SHA-256, review status and safe reason codes. It excludes raw macro values and credentials. The preflight confirms the configured series allowlist, latest observation quality/date/value, trusted HTTPS FRED host, and a **successful ingestion run with matching response SHA-256**.
- `config/ii.php` now defaults `fred.report_rights=[]`, meaning **all CSV export is denied unless every included series has an independently documented reviewed receipt**. The review receipt must also exist as a private local file in `ii-rights-reviews/`, match the configured SHA-256, and remain intact on each new export attempt; a configured hash alone cannot authorize export. A prospective approval must explicitly match `REVIEWED_ALLOWED`, `LOCAL_PERSONAL_NONCOMMERCIAL_CSV`, reviewed 64-character receipt SHA-256 and the configured original source owner. Neither a BYOK key, a shared FRED endpoint nor mere API access grants permission to redistribute.
- Added `MacroReportLineageService`: when all relevant reviewed receipts exist, the local personal-only CSV is SHA-256 hashed, linked to source receipt nodes and a `RENDER_FRED_PERSONAL_CSV_V1` transform, and checked with `verifyTrace()` before transmission. The response contains `X-II-Lineage-Node`, `X-II-Content-SHA256` and an explicit narrow scope. Numeric observations and CSV body are **not persisted** in lineage registry tables.
- The generic registry `can_export=false` remains unchanged. The reviewed local personal non-commercial download is a **separate, narrow application-level policy exception**, NOT permission for commercial redistribution or cloud transfer; future policy implementation must unify this exception with an auditable rights-grant primitive.
- Synthetic GitHub CI uses synthetic review receipts solely to prove the permitted test path; no genuine entitlement, rights clearance or purchased provider contract is implied.
- Tests extend `FredPipelineTest` for default-deny, preflight manifest, reviewed synthetic scope, SHA-256/ancestor lineage, failure on forged host, incorrect commercial scope, and mismatched ingestion receipt.
- The SEC normalization and other independent-source reporting paths require their own license/provenance decisions and remain beyond the FRED-specific gate. Existing signed installer and real Windows user acceptance are also still outstanding.

- **Revocation behavior:** removing or modifying a review receipt blocks further CSV downloads without deleting previously recorded report lineage. This preserves audit history but does not permit a new transfer.
