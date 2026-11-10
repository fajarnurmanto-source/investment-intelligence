# Multi-Issuer Fundamental Intelligence V2

**Status:** development acceptance passed, 10 November 2026; not deployed  
**Safety:** `NOT_ADMITTED`, `HOLD`, `TRADING_AUTHORITY=NONE`

## Capability

V2 reuses the immutable V1 source register and adds:

- `ii.issuer-batch.v2` JSON manifests with 1–20 CSV, XBRL/XML, or PDF files;
- one Laravel queue job, result, error, and explicit retry path per item;
- sector and fiscal-period classification;
- auditable IFRS taxonomy concept/release mapping, with unsupported extensions or dimensions held;
- version conflict detection without replacing prior source documents or normalized periods;
- separately registered dated market prices with source, rights, currency, per-share unit, adjustment status, timestamp, and SHA-256;
- sector/period screening, two-way ratio sorting, cross-issuer comparison, conflict visibility, and CSV export.

The batch manifest and every item are checksummed independently. Uploaded staging files are private and removed only after their immutable source copy has been created. A failed item does not roll back successful siblings.

## Real-data acceptance set

The acceptance execution uses official issuer publications as internal-research evidence. It does not put issuer PDFs or a redistributable dataset in Git.

| Issuer | Official publication | Unit | FY2024 / FY2023 evidence used |
|---|---|---:|---|
| TLKM.JK | Telkom Annual Report 2024 | Rp billion | revenue 149,967 / 149,216; attributable profit 23,649 / 24,560; assets 299,675 / 287,042; total equity 162,490 / 156,562; attributable equity 142,094 / 135,744; OCF 61,600 / 60,581; shares 99,062,216,600; financing debt including leases 76,868 / 68,124; cash 33,905 / 29,007; EBITDA 75,029 / 77,579. |
| ASII.JK | Astra Annual Report 2024 | Rp billion | revenue 330,920 / 316,565; attributable profit 34,051 / 33,839; assets 472,925 / 445,405 (2023 restated); total equity 271,496 / 250,424; attributable equity 213,165 / 198,640; OCF 45,029 / 33,746; shares 40,483,553,140; cash 48,439 / 41,136. Debt and EBITDA are left null because this acceptance set does not assert a reconciled V2 definition for them. |
| UNVR.JK | Unilever Indonesia Annual Report 2024 | Rp million | sales 35,138,643 / 38,611,401; profit 3,368,693 / 4,800,940; assets 16,046,195 / 16,664,086; total/attributable equity 2,149,267 / 3,381,238; OCF 3,959,982 / 7,118,088; shares 38,150,000,000; cash 671,180 / 1,020,598. Debt and EBITDA are left null. |

Official URLs:

- Telkom: <https://www.telkom.co.id/minio/show/data/lampiran/1745244391951_original_Annual%20Report%20Telkom%202024%20%281%29.pdf>
- Astra: <https://r2.astra.co.id/annual-reports/documents/Astra-Annual-Report-2024.pdf>
- Unilever Indonesia: <https://www.unilever.co.id/files/indonesia-annual-reports-2024.pdf>

Each item is registered as `public_disclosure`, `internal_research`, `redistribution_allowed=false`, `OFFICIAL_PUBLISHER`, and `VERIFIED_AGAINST_SOURCE`. Public availability is not treated as redistribution permission.

### Isolated real-data execution

The acceptance database and private input evidence live outside Git under `II/recovery/V2_ACCEPTANCE_20261110`. Batch `01a1c5de-b323-7240-bdf0-606939361924` completed with three successful items, zero failures, three immutable documents, six normalized periods, and three cross-issuer screener rows. The manifest SHA-256 is `225f0eccf55cb3df3b845c27c324d6f24de094ce976f053fb0f35d924aec8136`.

All three snapshots were `COMPLETE` with score 100, separate source/checksum provenance, `INTERNAL_RESEARCH_ONLY`, and no redistribution permission. Because no rights-cleared dated market-price input was available, P/E, P/BV, and EV/EBITDA remained null with `N/A_MISSING_MARKET_PRICE`. `NOT_ADMITTED`, `HOLD`, and `TRADING_AUTHORITY=NONE` remained unchanged. This execution did not read or migrate the production database.

## Valuation rules

Market price is an immutable, dated observation separate from the financial report. A valuation is calculated only when:

1. the price is positive and has an explicit date;
2. its currency matches the financial report currency;
3. the unit is `per_share` and the price date does not predate the report period end;
4. usage rights are explicitly internal/licensed/permitted;
5. the required financial components exist and use the normalized unit.

P/E requires positive earnings per share. P/BV requires positive attributable book value per share. EV/EBITDA additionally requires shares, debt, cash, and positive EBITDA. Missing or incompatible inputs remain null and expose a specific `N/A_*` status.

## Conflict semantics

For the same user, issuer, report label, fiscal year, and period end, values from a new document are compared with every prior version. Identical values receive `CONSISTENT_VERSION`; any differing normalized metric sets both versions to `VALUE_CONFLICT` and records document IDs, metric, period, and both values. Neither source nor period is overwritten.

## Batch manifest

Download the synthetic manifest and CSV templates from the authenticated Fundamental Hub. A manifest item contains a file name plus the same source, rights, period, currency, multiplier, and reconciliation metadata used by single-file intake. Path components, duplicate names, unknown columns, and unconfirmed rights are rejected.
