# II V13 — Mandatory Decisions and Evidence Acceptance Gates

Date: 2026-11-24
Scope: IDX Edge PRO third-party connector pilot
Branch baseline at assessment: 72ea57cc4992f90dbbdd1ef429fad001e5361f50 (PR #26)

## Binding development decisions

1. RESULTS-FIRST: measured runtime outcomes and auditable evidence take precedence over feature-count claims.
2. REUSE-FIRST: native Laravel HTTP/Storage/Console/Testing, Composer and licensed existing modules before custom infrastructure.
3. REAL-DATA-FIRST: distinguish provider-live payloads from synthetic HTTP fixtures; fixture success proves behavior, not data provenance or accuracy.
4. LARAVEL-ONLY: one canonical application runtime; no alternative Python production stack.
5. BENCHMARK-DRIVEN: compare available product capabilities without admitting third-party data until independently verified.
6. AUTOMATED-MINIMUM-QA: deterministic negative tests, focused CI, full regression and Windows-local acceptance at the release candidate SHA.
7. SINGLE MASTER ROADMAP: IDX Edge PRO is a substream of II real-data activation, not a parallel master roadmap.
8. PRODUCTION_ADMISSION=DENIED, NOT_ADMITTED/HOLD/TRADING_AUTHORITY=NONE until documented evidence and authorization. No automatic screener, portfolio, trading, or redistribution admission.
9. No IDX website scraping, no invented financial mappings, no hard-coded secrets, no APP_KEY rotation or production SQLite mutation.
10. Preserve idxapi.io as separate fallback. No merge or deployment before the established acceptance/approval gates.

## Verified GitHub implementation versus acceptance

Observed PR #26 at assessment: OPEN, not merged, head 72ea57cc4992f90dbbdd1ef429fad001e5361f50; Linux GitHub Actions run 45350716802 completed successfully. Four feature tests use Http::fake responses.

- Adapter: PRESENT — `IdxEdgeFinancialEvidenceService`; fixed HTTPS host and X-API-Key; rejects common HTTP/JSON/identity failures.
- Runtime secret configuration: PRESENT — `II_IDXEDGE_KEY` through Laravel config. No evidence of actual local secret configuration is available from GitHub.
- Raw evidence archival: PARTIAL — provider response body is SHA-256 hashed, but `IIIdxEdgePilot` archives a JSON-encoded *wrapper containing decoded payload*, not the exact original raw response bytes. Retain source checksum and raw-byte sidecar separately with secure permissions and no secret leakage; test `hash_file('sha256', raw_path) === source_sha256`.
- Live fetch: NOT VERIFIED — feature tests mock the provider. No authenticated real-provider receipt and no actual-provider raw bytes were available in this review.
- Schema / period / currency / unit / rights: NOT VERIFIED — the service flags period and currency UNVERIFIED, numeric UNRECONCILED, redistribution_allowed=false.
- Normalizer: NOT VERIFIED — no verified schema-driven normalizer is included among PR #26 changed files; do not infer a tested real-data normalizer from synthetic fixture values.
- Regression: GitHub Actions SUCCESS at PR head; Windows-local runtime regression, authenticated review and official-filing reconciliation are separate acceptance gates.

## Next phase required sequence

1. Preserve current immutable baseline and GitHub PR. Do not publish credentials or confidential response body in PR/issues/logs.
2. Implement a private raw-response byte sidecar and separate metadata manifest; verify SHA-256 over stored bytes. Retain fail-closed size, TLS and URL constraints. Ensure no consumer can automatically import sidecar into financials.
3. Add tests covering byte-exact checksum, duplicate fetch semantics, provider error bodies, identity inconsistencies, transport/TLS guards, 5 MB ceiling, archive storage failure, and no writes to canonical financial tables. Run CI.
4. Under the user's local runtime secret, fetch BBCA/TLKM/ASII with guarded explicit invocation only; record timestamps, HTTP status, checksum, raw file path, provider rights assessment. Do not log keys or publish vendor-licensed data.
5. Inspect actual provider response schema: issuer identity, year/quarter, reporting standard, statement type, currency, scale, consolidated/standalone, restatements and provenance. Unknowns remain UNKNOWN.
6. Reconcile supported values with issuer's authorized official FY2024/FY2025 filings. If rights do not permit the intended use or fields are ambiguous, ABSTAIN.
7. Build separate opt-in normalizer and tests only for verified fields; preserve vendor raw and attribution; map no unverified numbers.
8. Run Laravel focused tests, full regression, Linux CI, isolated Windows-local SQLite acceptance and screener non-regression at one pinned commit. Record exact test counts and artifacts.
9. Only then seek established authorization for merge/deployment. Production numeric admission remains DENIED until its separate acceptance.

## Evidence receipt schema (design only, not a claim of live execution)

```json
{
  "provider": "IDX_EDGE_PRO_THIRD_PARTY",
  "ticker_requested": "BBCA",
  "fetched_at": null,
  "http_status": null,
  "raw_private_path": null,
  "source_sha256": null,
  "raw_hash_verified": false,
  "schema_version": "UNKNOWN",
  "period": "UNVERIFIED",
  "currency": "UNVERIFIED",
  "scale": "UNVERIFIED",
  "rights": "UNVERIFIED",
  "official_filing_reconciliation": "NOT_DONE",
  "numeric_admission": "NOT_ADMITTED",
  "decision": "HOLD",
  "trading_authority": "NONE"
}
```

No live retrieval, local Windows test, merge, or deployment is asserted by creation of this document.
