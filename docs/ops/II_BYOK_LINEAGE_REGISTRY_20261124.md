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
