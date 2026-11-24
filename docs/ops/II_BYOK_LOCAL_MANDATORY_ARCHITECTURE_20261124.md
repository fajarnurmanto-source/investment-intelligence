# Investment Intelligence — Mandatory Local BYOK Architecture Cutover

Date: 2026-11-24
Decision: **BYOK_LOCAL**, one isolated local installation per independent owner. The former "one owner across multiple devices / synchronization" objective is **RETIRED**.
Implementation state: **PARTIAL; draft PR only; no live-key/provider acceptance**.

## Decision simulation (qualitative, not commercial price estimates)

| Criterion | Developer-owned market-data key | BYOK through a developer server | BYOK on user's local Laravel runtime |
|---|---|---|---|
| Credentials per end user | No | Yes | **Yes** |
| Provider data arrives at developer infrastructure | Possibly | **Yes** | **No** |
| Local-only market-data processing | Possible | No | **Yes** |
| Redistribution licensing exposure | High / vendor-dependent | Still material | Lower when provider explicitly permits this model |
| Data distribution across user accounts | Centralized risk | Centralized risk | No shared transport by design |
| Fit for downloadable Windows installer | Medium | Low | **High** |
| Export of licensed BYOK data | Restricted by source | Restricted by source | **Deny by default** |

**Selection:** user-device local Laravel + individual supplier subscription + direct egress. BYOK does not exempt the app/user from non-display, retention, export, entitlement, device/session and regional exchange requirements.

## Mandatory architectural boundaries

1. **One local runtime, one data boundary.** No multi-device identity, synchronization, cloud relay, shared market-data key, centralized price cache, multi-tenant feed or trading execution.
2. **Provider-neutral contract, provider-specific rights.** First adapter MarketData.app delayed stock quotes; Finnhub and Alpaca remain unimplemented until provider-specific approval and end-to-end acceptance. Alpaca IEX is NOT SIP.
3. **Encrypted local vault.** API key supplied by user through authenticated localhost interface, encrypted using Laravel Crypt with unique installation APP_KEY, stored in per-user SQLite row; value never printed in logs/UI. **This is not DPAPI yet**; local Windows filesystem ACL and APP_KEY secrecy remain mandatory. DPAPI/native vault remains separate hardening milestone.
4. **Rights first.** Default deny export, clipboard, cloud-LLM transfer, external API/webhooks, sync, raw archive, background acquisition or persistent market-data cache for MarketData BYOK. Any missing/unknown capability => DENY.
5. **Non-export architecture.** The MarketData quote is transient view-only; no II price-document or portfolio tables updated. Existing user-created filings/portfolio files and public SEC/FRED research workflows are different sources and do not inherit MarketData rights, but must be audited for provenance before any BYOK-feed data can enter them.
6. **Fail closed.** API error/entitlement/quota/response ambiguity never marks real-time, verified, admitted, or tradable. `NOT_ADMITTED/HOLD/TRADING_AUTHORITY=NONE`.
7. **Source-key boundary.** No central API key or auto-sync key in installer, .env, README, GitHub secrets, app defaults, or logs.
8. **First-run UX:** local account -> enter own provider API key -> manual quote preview with source/data-freshness badge -> no export.
9. **Installer remains local-only.** Reuse M08 packaging staging and first-install isolated APP_KEY/SQLite; no two-device synchronization or claims of signed/usable installer.
10. **User responsibility:** account agreement and exchange entitlement verified directly with vendor. Do not attempt to bypass per-IP/session restrictions.

## Change inventory

**Added:** `ByokRightsPolicy`, `LocalByokCredentialVault`, `MarketDataByokQuoteService`, `LocalByokController`, encrypted credentials migration, `ii/byok.blade.php`, authenticated localhost endpoints, synthetic BYOK tests.

**Deleted as excess:** `IIIdxApiFreeSync.php`, `IdxApiFreeFundamentalsService.php`, `IdxApiFreeFundamentalsV12Test.php`, old IDXAPI free activation guide. Removed cron schedule, provider configuration from .env and obsolete UI/routing/controller.

**Retained deliberately:** Git `tools/sync-II.ps1` is **source-code repository synchronization**, not user market data synchronization. User-uploaded documents and related exports are not inherently vendor market-data exports; they must remain source-scoped. SEC/FRED public/official ingestion remains separate from the BYOK market-data subscription. Previous V13 IDX Edge branch PR #26 is NOT merged and should not reintroduce shared-key data workflows.

## Source policy references, reviewed 24 November 2026
- MarketData explicit BYOK conditions: https://www.marketdata.app/education/licensing/bring-your-own-key/
- MarketData authentication: https://www.marketdata.app/docs/api/authentication/
- MarketData delayed quotes: https://www.marketdata.app/docs/api/stocks/quotes/
- Alpaca feed distinctions: https://docs.alpaca.markets/us/docs/market-data-faq

## Acceptance ladder

1. **A1** source-code regression CI and no legacy routes/schedules.
2. **A2** local HTTP fake-transport, encrypted vault tests, remote-network denial, no imports or CSV derived from BYOK.
3. **A3** controlled test with each user's own API key, validated entitlement and explicit license review (no live key in Git).
4. **A4** Windows signed installer with DPAPI/OS credential hardening, single-user first-run, successful real Laravel migrations and login.
5. **A5** full provenance propagation + export denial in *all* downstream reports/screens; rights-aware LLM and data lifecycle.
6. **A6** independent legal/provider review for any non-display, backtesting, provider historical caches or commercial software distribution.

No merger, source-rights admission or production deployment is implied by this document.

## Rights boundary increment — 24 November 2026

- Added `ByokIntakeFirewall` on market-price single-record writes, CSV batch imports **before file storage**, and fundamental metadata validation. An explicitly declared MarketData.app BYOK origin is refused rather than being relabeled `licensed_internal` and entering existing exportable canonical tables.
- Added `BlockRestrictedByokExports` to issuer/fundamental coverage CSV and screener CSV endpoints. Historical `ii_issuer_market_prices` rows identifying MarketData.app prevent CSV generation for that owner, while independent owners remain isolated.
- Synthetic tests `ByokDataBoundaryTest` and `ByokLegacyExportFirewallTest` enforce the above and check that denied batch import leaves neither price rows nor persisted CSV evidence.
- **Known limitation:** these rules enforce known/declarative provenance, not forensic detection of user-retyped values. They do not yet implement immutable end-to-end lineage across portfolio, strategy, macro and all downloadable report formats. The in-app UI cannot technically stop manual screenshots/typing; the system provides no export mechanism for the vendor feed.
- No claim of vendor licensing clearance, native DPAPI vault, live feed validation, signed installer, launch-ready release or multi-device support. `NOT_ADMITTED / HOLD / TRADING_AUTHORITY=NONE` remains mandatory.

## Windows DPAPI hardening & excess-file audit — 24 November 2026

- **Implemented and CI verified:** `WindowsDpapiCredentialProtector` and `WindowsDpapiCredential.ps1` use Windows `ProtectedData` CurrentUser with fixed application entropy. Secret input and decrypted output travel only in local process standard streams; no API key is passed in command arguments, environment variables or temporary files. PowerShell's `System.Security` assembly must be loaded explicitly for reliable Windows PowerShell 5.1 execution.
- On Windows, `LocalByokCredentialVault` now **requires** DPAPI-formatted ciphertext. Previous unprefixed Laravel Crypt records cannot be silently decrypted; the UI shows disconnected so the owner can provide the key again. Non-Windows dev tests use versioned `laravel-local-v1:` encryption with the local Laravel APP_KEY.
- **Validated before final polish:** Windows synthetic DPAPI Protect/Unprotect roundtrip and full Laravel integration passed [BYOK CI run 45403861773](https://github.com/fajarnurmanto-source/investment-intelligence/actions/runs/45403861773); both Linux and Windows passed [Laravel CI run 45403861811](https://github.com/fajarnurmanto-source/investment-intelligence/actions/runs/45403861811), Windows installer staging passed [run 45403861819](https://github.com/fajarnurmanto-source/investment-intelligence/actions/runs/45403861819). Newer reconnect behavior and cleanup commits must pass CI separately.
- **Cleanup completed:** removed obsolete `laravel_app/README.md` duplicate because repository-root README + this architecture decision are the canonical references; earlier branch changes removed `IIIdxApiFreeSync.php`, `IdxApiFreeFundamentalsService.php`, `IdxApiFreeFundamentalsV12Test.php`, old IDXAPI usage guide, config, cron, web route and obsolete UI. Removed temporary diagnostic stage logging from DPAPI helper and its CI setup following synthetic verification.
- **Retained intentionally, not trash:** `tools/sync-II.ps1` + `sync-II.cmd` synchronize **developer source code via Git**, not owner market data. Windows start/stop/restart wrappers and production rollback tools remain needed for local runtime. Existing SEC/FRED workflows and user-authored CSV imports have independent provenance and must not be deleted indiscriminately.
- **Not completed:** real user-provided vendor key validation, Windows signed installer, machine clean-install, full derived-data lineage across every report/export, or owner-device acceptance; PR #28 remains draft/unmerged.

## Source-lineage hardening — 24 November 2026, follow-up

- Expanded `BlockRestrictedByokExports` to inspect both `ii_issuer_market_prices` and joined `ii_issuer_documents` → `ii_issuer_sources` for the current authenticated user. A legacy restricted fundamental-source document now blocks the user's three fundamental/screener CSV exports even if no market-price row exists.
- `ByokLegacyExportFirewallTest` now creates a valid independent fundamental fixture, relabels its stored source to simulate pre-cutover legacy BYOK contamination and asserts all three CSV routes reject it; a separate user remains unblocked.
- Synthetic-demo research-case/workspace exports and SEC/FRED macro reports do not consume these issuer-price/import sources by current design; they are retained as independent source workflows. Do not globally block unrelated source classes merely because the user has a restricted provider subscription.
- Removed obsolete `synthetic-only` comment from `routes/web.php`. Historical `docs/data` retained as provenance and version history, not deleted as trash; `sync-II.cmd`/`tools/sync-II.ps1` only manage developer **source Git**, not BYOK market-data synchronization.
- These checks are known-metadata safeguards, not proof of full immutable column-level numeric lineage; manual retyping/mislabeling cannot be reliably inferred from numbers. No live market-data API key, production database, or deployed runtime was modified.

## Derived snapshot lineage boundary — 24 November 2026 (increment)

- `ByokSnapshotLineage` now classifies known restricted provider provenance from source name, publisher, provider terms URL, primary source URL and separate market-price source/URL. Unknown source rights are `NOT_ASSESSED`, never positively declared exportable.
- `FundamentalSnapshotService::latestByTicker()` applies this policy **before** the shared issuer records reach fundamental views, coverage calculations and screener ranking. Known contaminated sources receive `byok_lineage=RESTRICTED_BYOK_SOURCE_DETECTED`, `byok_export_clearance=DENIED` and `N/A_BYOK_RESTRICTED_SOURCE`. Financial metrics and per-year numeric facts are suppressed; the ticker, time period and provenance identifiers remain. Decisions stay `NOT_ADMITTED / HOLD / TRADING_AUTHORITY=NONE`.
- The legacy compatibility writer `FundamentalSnapshotService::save()` now refuses restricted BYOK payloads before any JSON file is stored. `ByokIntakeFirewall` reuses the same classifier, removing duplicate PHP matching logic between write and read paths.
- `ByokSnapshotLineageTest` covers source-derived metrics removal, independent-source preservation, actual imported legacy issuer document quarantine and forbidden legacy JSON storage. New tests also cover restriction detected solely by provider price URL or terms URL.
- **Boundary limitation:** classification detects declared/known provenance, not deliberate value retyping, mathematically reconstructed numbers or data that lost its source metadata. This is not proof of a complete immutable numeric lineage graph, third-party legal permission or a signed Windows release. No live provider key, market-data import or production database was modified.

## Screener quarantine and legacy storage protection — 24 November 2026

- `FundamentalScreenerService` now excludes `RESTRICTED_BYOK_SOURCE_DETECTED` snapshots **before** scoring, filtering or ranking. A known contaminated legacy BYOK document cannot produce a derived screener score, even if it was ingested before the cutover.
- `ByokSnapshotLineageTest` confirms quarantined values do not enter the screener while an independent-source record remains eligible.
- `FundamentalSnapshotService::save()` now refuses known restricted provider data **before** writing legacy JSON snapshots. Tests confirm rejected uploads leave no file, while independent user-owned snapshots remain supported.
- Source detection is consolidated via `ByokSnapshotLineage::isRestricted` and reused by `ByokIntakeFirewall`; this removes duplicate matching logic while preserving import guard behavior.
- Data lineage remains metadata-dependent; this is not a forensic guarantee against user-misrepresented sources, no license grant and no approval for downstream external model transmission.
