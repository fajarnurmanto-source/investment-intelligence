# Telkom FY2024/FY2023 Fundamental Reconciliation

Source: PT Telkom Indonesia (Persero) Tbk, *Annual Report 2024*, official publisher URL:
<https://www.telkom.co.id/minio/show/data/lampiran/1745244391951_original_Annual%20Report%20Telkom%202024%20%281%29.pdf>

Usage classification: public disclosure, internal research only, redistribution not asserted. The normalized CSV is operational input, not a commercial redistributable dataset. All values below are consolidated and expressed in Rp billion except shares.

| Normalized metric | Definition used by II | FY2024 | FY2023 | Official location | Status |
|---|---|---:|---:|---|---|
| `revenue` | Consolidated revenues for the year | 149,967 | 149,216 | AR pp.24–27, Key Financial Data Overview; audited statements p.2 | VERIFIED |
| `net_income_attributable` | Profit attributable to owners of the parent | 23,649 | 24,560 | Audited consolidated profit/loss statement p.2 | VERIFIED |
| `total_assets` | Consolidated total assets at year end | 299,675 | 287,042 | Audited statement of financial position p.1 | VERIFIED |
| `total_equity` | Total equity including non-controlling interests | 162,490 | 156,562 | Audited statement of financial position p.1 | VERIFIED |
| `equity_attributable` | Equity attributable to owners of the parent | 142,094 | 135,744 | Audited statement of financial position p.1 | VERIFIED |
| `operating_cash_flow` | Net cash provided by operating activities | 61,600 | 60,581 | Audited cash-flow statement p.4 | VERIFIED |
| `shares_outstanding` | Issued and fully paid shares at year end | 99,062,216,600 | 99,062,216,600 | AR stock information pp.28–30; Note 21 p.65 | VERIFIED |
| `total_debt` | Total liabilities from financing activities, including bank loans, bonds/other borrowings, and lease liabilities | 76,868 | 68,124 | Note 39 p.110, changes in liabilities arising from financing activities | VERIFIED |
| `cash_and_equivalents` | Cash and cash equivalents at year end | 33,905 | 29,007 | Audited statement of financial position p.1; Note 3 | VERIFIED |
| `ebitda` | Issuer-reported consolidated EBITDA | 75,029 | 77,579 | AR pp.24–27, Key Financial Data Overview | VERIFIED |
| `market_price` | Observed price per share with explicit as-of date | null | null | Deliberately not supplied to the internal normalized import | VERIFIED N/A |

## Resolved mismatches

- The original fixture values `52,909` (2024) and `47,699` (2023) equal financing debt **excluding lease liabilities**: total financing liabilities less lease liabilities of `23,959` and `20,425`. That subset is valid but was incorrectly labelled `total_debt`. II now uses `76,868` and `68,124`, consistent with Telkom's total-debt definition including leases.
- The original FY2024 EBITDA fixture `75,579` does not match the 2024 Annual Report. The verified amount is `75,029`. FY2023 `77,579` was already correct.

## Derived checks

- FY2024 debt/equity = `76,868 / 162,490 = 0.4731` (47.31%), consistent with Telkom's disclosed 47.3% after rounding.
- FY2024 closing-equity ROE used by II = `23,649 / 142,094 = 16.64%`. This definition intentionally differs from issuer ratios that may use average or total equity.
- Revenue growth = `0.50%`; attributable earnings growth = `-3.71%`.
- P/E, P/BV, and EV/EBITDA remain `N/A_MISSING_MARKET_PRICE`; II will not infer a price or date.

Status values indicate reconciliation against the named tables, not investment admission. The imported record remains `NOT_ADMITTED`, `HOLD`, and `TRADING_AUTHORITY=NONE`.
