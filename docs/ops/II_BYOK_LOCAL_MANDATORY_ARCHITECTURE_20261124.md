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
