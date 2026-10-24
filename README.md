# Investment Intelligence — Company Research VS2

VS2 is a **working synthetic-only** FastAPI research vertical slice. It reuses VS1 B1 evaluator, adds 3-period financial metric panels, provenance and quality contracts, and keeps research state `HOLD`. All market, issuer, rights, and ledger data in canonical II remain untouched.

## Run
`python -m pip install -r requirements.txt pytest`
`python -m pytest -q`
`python -m uvicorn app.main:app --host 127.0.0.1 --port 8811`

## Important boundary
* `app/research_adapter.py` is a fixture adapter, **not** a connector to live/canonical DuckDB. No automatic real-data access or promotion.
* Do not use hypothetical scenario outcomes for investment decisions. `NOT_ADMITTED`, `NONE`, and `HOLD` remain effective.
* No production secrets, financial reports, source documents, or DBs in this package.
* Existing VS1 endpoints and tests retained.

## Next engineering seam
Once rights and per-file admission are adjudicated, implement a separate explicit read-only service contract for approved exports with source identity, as-of date, metric lineage and rights context. Do not point this UI directly at canonical database.
