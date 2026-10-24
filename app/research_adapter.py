"""VS2 contract-first research adapter. No filesystem market-data or ledger access.
Only synthetic in-package fixtures; deliberate rejection of non-demo issuers.
This is a contract for a future approved read-only engine export, not that engine.
"""
from __future__ import annotations
from copy import deepcopy
from typing import Any

ROWS: dict[str, dict[str, Any]] = {
    "DEMO2": {"name":"Synthetic Consumer Business", "sector":"Consumer", "thesis":"Can net margin remain above 15%?",
        "history":[{"period":"FY2023","net_margin_pct":12.2,"revenue_index":100.0}, {"period":"FY2024","net_margin_pct":14.7,"revenue_index":110.5},{"period":"FY2025","net_margin_pct":18.0,"revenue_index":123.6}]},
    "DEMO3": {"name":"Synthetic Industrial Business", "sector":"Industrial", "thesis":"Can net margin remain above 15%?",
        "history":[{"period":"FY2023","net_margin_pct":13.1,"revenue_index":100.0}, {"period":"FY2024","net_margin_pct":11.4,"revenue_index":96.0},{"period":"FY2025","net_margin_pct":8.0,"revenue_index":104.2}]},
}

def get_universe():
    return [{"ticker": t, "name": c["name"], "sector":c["sector"],"data_class":"SYNTHETIC_FIXTURE", "admission":"NOT_ADMITTED"} for t,c in ROWS.items()]

def get_research(ticker: str):
    if ticker not in ROWS:
        raise KeyError('Ticker excluded')
    row = deepcopy(ROWS[ticker])
    return {"ticker":ticker, **row,
            "metric_definitions":{"net_margin_pct":"Synthetic net margin percentage; not reconstructed from audited statements", "revenue_index":"Synthetic revenue index, base FY2023=100; NOT actual currency revenue"},
            "provenance":{"source_id":f"SYNTHETIC_FIXTURE_VS2_{ticker}","source_type":"GENERATED_DEMO", "point_in_time":"NOT_APPLICABLE_SYNTHETIC", "rights":"SYNTHETIC_ONLY", "lineage":"In-package synthetic constant; no canonical database connection"},
            "quality":{"state":"COMPLETE_UNVERIFIED", "blocking_reasons":["No admitted real-source evidence", "No issuer-level rights adjudication"]},
            "admission":"NOT_ADMITTED", "authority":"NONE", "effective_decision":"HOLD",
            "valuation_authorized":False,"trading_authorized":False}
