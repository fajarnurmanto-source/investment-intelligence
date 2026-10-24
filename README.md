# Investment Intelligence: Company Research vertical slice v0.1

A **running synthetic-only product prototype**, not a replacement for the canonical II codebase.

## What works
- Single integrated responsive Company Research UI
- Synthetic issuer overview and narrative thesis
- User-entered hypothetical scenario using **the unchanged II B1 evaluator** bundled under `legacy/b1.py`
- Explicit separate scenario vs effective `HOLD` status
- Read-only fake universe; no accounts, database, live data, or trade execution
- API docs at `/api/docs`

## Run from isolated candidate folder (Windows PowerShell)
```powershell
cd 'D:\path\to\ii_product_slice'
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe -m pytest -q
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8810
```
Open http://127.0.0.1:8810/ . Install uses public Python package index; do not use any II production environment.

## Actual integration plan (no speculative compatibility claims)
1. Review canonical II master roadmap and live engine interfaces.
2. Define a read-only adapter to II_APP and evidence/financial services; prohibit direct opening of canonical DuckDB from web app.
3. Show user-authorized issuer data only after rights, provenance, and admission checks; fail closed.
4. Preserve immutable legacy ledgers and original code; add service boundary tests.
5. Select Laravel/Filament vs FastAPI based on real host/dependency constraints and product UX needs; avoid a rewrite just for framework uniformity.

## Safety boundary
All tickers DEMO2/DEMO3 are invented. The company overview's `margin` is illustrative and never fed into B1. Scenario numbers are entered by the user and marked synthetic. No code in this package references, accesses, changes or copies `D:\Research\INVESTMENT RESEARCH`, `runtime_data`, DuckDB, broker APIs, or remote Git. All effective decisions remain HOLD. No production admission, valuation, or trading is authorized.
