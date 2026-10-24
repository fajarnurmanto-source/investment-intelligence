"""II Company Research v0.1: safe synthetic product vertical slice.
No production data, database access, or investment authority.
"""
from __future__ import annotations
import html
from pathlib import Path
import sys
from fastapi import FastAPI, HTTPException
from fastapi.responses import HTMLResponse
from pydantic import BaseModel, Field
from app.research_adapter import get_research, get_universe

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'legacy'))
from b1 import Evidence, Thesis, evaluate  # reuse original B1 domain evaluator

app = FastAPI(title='Investment Intelligence — Company Research', version='0.1.0', docs_url='/api/docs', redoc_url=None)

# Explicitly synthetic/demo cases, fixed and read-only. Never access legacy runtime_data.
COMPANIES = {
    'DEMO2': {'name': 'Synthetic Consumer Business', 'sector': 'Consumer', 'thesis': 'Maintain net margin above 15%', 'margin': 18.0, 'period': 'FY2025'},
    'DEMO3': {'name': 'Synthetic Industrial Business', 'sector': 'Industrial', 'thesis': 'Maintain net margin above 15%', 'margin': 8.0, 'period': 'FY2025'},
}

class Scenario(BaseModel):
    metric: str = Field(pattern=r'^Net_Margin_Pct$')
    observed_value: float = Field(ge=-100, le=100)
    minimum_value: float = Field(ge=-100, le=100)
    falsification_below: float = Field(ge=-100, le=100)

@app.get('/api/v2/companies')
def companies_v2():
    return get_universe()

@app.get('/api/v2/companies/{ticker}/research')
def company_research_v2(ticker: str):
    if ticker not in COMPANIES:
        raise HTTPException(status_code=404, detail='Not in synthetic universe')
    return get_research(ticker)

@app.get('/api/health')
def health():
    return {'status':'ok','scope':'SYNTHETIC_ONLY','admission':'NOT_ADMITTED'}

@app.get('/api/companies')
def companies():
    return [{'ticker': ticker, **company, 'evidence_status':'NOT_ADMITTED'} for ticker, company in COMPANIES.items()]

@app.get('/api/companies/{ticker}')
def company(ticker: str):
    if ticker not in COMPANIES:
        raise HTTPException(status_code=404, detail='Synthetic company not found')
    return {'ticker':ticker, **COMPANIES[ticker], 'evidence_status':'NOT_ADMITTED','effective_decision':'HOLD'}

@app.post('/api/companies/{ticker}/scenario')
def scenario(ticker: str, data: Scenario):
    if ticker not in COMPANIES:
        raise HTTPException(status_code=404, detail='Synthetic company not found')
    if data.falsification_below > data.minimum_value:
        raise HTTPException(status_code=422, detail='Falsification boundary must not exceed support threshold')
    # Synthetic input is intentionally NOT taken from company margin above.
    result = evaluate((Evidence('USER_HYPOTHETICAL','SYNTHETIC_MARGIN','VERIFIED_SYNTHETIC',data.observed_value),),
                      Thesis('USER_SCENARIO','SYNTHETIC_MARGIN',data.minimum_value,data.falsification_below))
    return {
        'ticker': ticker,
        'input_source':'USER_ENTERED_HYPOTHETICAL',
        'scenario_result':result,
        'effective_research_state':'HOLD',
        'evidence_admission':'NOT_ADMITTED',
        'valuation_authorized':False,
        'trading_authorized':False,
    }

@app.get('/', response_class=HTMLResponse)
def index():
    return HTMLResponse((ROOT / 'app' / 'static' / 'index.html').read_text(encoding='utf-8'))
