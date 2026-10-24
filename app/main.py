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
    return HTMLResponse('''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>II · Company Research</title>
<style>:root{font-family:Inter,system-ui,sans-serif;color:#d8e6f8;background:#0c1424}body{margin:0}main{max-width:1080px;margin:0 auto;padding:28px}h1{font-size:30px}p{color:#95a9c5}.tag{color:#ffce75;background:#503f21;padding:6px 10px;border-radius:30px;font-size:12px}.layout{display:grid;grid-template-columns:280px 1fr;gap:22px;margin-top:24px}.panel{background:#152238;border:1px solid #293a56;border-radius:16px;padding:20px}button{cursor:pointer;background:#23466b;color:white;border:1px solid #44698f;padding:11px;border-radius:9px}button:hover{background:#315d8c}.company{display:block;width:100%;margin-bottom:10px;text-align:left}label{display:block;font-size:13px;color:#9db2d0;margin-top:12px}input{box-sizing:border-box;width:100%;padding:12px;background:#0c1728;border:1px solid #3a516e;color:#fff;border-radius:8px;margin-top:6px}strong{color:white}.muted{color:#9db2d0}.kpi{display:flex;gap:12px;flex-wrap:wrap}.kpi span{padding:10px 14px;border-radius:8px;background:#203149}pre{white-space:pre-wrap;word-wrap:break-word;background:#0c1728;padding:16px;border-radius:10px;font-size:13px}hr{border:0;border-top:1px solid #34445e;margin:20px 0}@media(max-width:700px){.layout{grid-template-columns:1fr}}</style></head><body><main><span class="tag">SYNTHETIC · NOT ADMITTED · RESEARCH HOLD</span><h1>Company Research Workstation</h1><p>A working II product slice: issuer overview, narrative thesis, scenario evaluator, decision trace. No live data or trading.</p><div class="layout"><aside class="panel"><h2>Research universe</h2><div id="companies">Loading…</div></aside><section class="panel"><h2 id="headline">Select a synthetic company</h2><p id="description"></p><div class="kpi"><span>Data: <strong>NOT_ADMITTED</strong></span><span>Effective decision: <strong>HOLD</strong></span></div><hr><h3>Hypothetical thesis scenario</h3><p>Enter hypothetical numbers manually. The observed metric shown in the company overview is never used as admitted evidence.</p><form id="scenario"><label>Hypothetical net margin (%)<input id="observed" type="number" min="-100" max="100" step="0.1" value="8" required></label><label>Support threshold (%)<input id="minimum" type="number" min="-100" max="100" step="0.1" value="15" required></label><label>Falsification below (%)<input id="falsify" type="number" min="-100" max="100" step="0.1" value="10" required></label><p><button type="submit">Evaluate hypothetical scenario</button></p></form><div id="result" role="status" aria-live="polite"></div></section></div></main>
<script>let selected='DEMO2';const el=id=>document.getElementById(id);async function get(u){let r=await fetch(u);if(!r.ok)throw Error(await r.text());return r.json()}function txt(node,t){node.textContent=t}async function select(t){selected=t;const c=await get('/api/companies/'+encodeURIComponent(t));txt(el('headline'),c.ticker+' — '+c.name);txt(el('description'),c.sector+' · '+c.period+' · Narrative thesis: '+c.thesis+' · Example displayed margin: '+c.margin+'% (unadmitted)');txt(el('result'),'No scenario run. Effective decision: HOLD.')}async function load(){try{const cs=await get('/api/companies');el('companies').replaceChildren(...cs.map(c=>{let b=document.createElement('button');b.className='company';b.textContent=c.ticker+' · '+c.name;b.onclick=()=>select(c.ticker);return b}));await select(selected)}catch(e){txt(el('companies'),String(e))}}el('scenario').onsubmit=async e=>{e.preventDefault();try{const r=await fetch('/api/companies/'+encodeURIComponent(selected)+'/scenario',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({metric:'Net_Margin_Pct',observed_value:Number(el('observed').value),minimum_value:Number(el('minimum').value),falsification_below:Number(el('falsify').value)})});const d=await r.json();if(!r.ok)throw Error(JSON.stringify(d));txt(el('result'),'HYPOTHETICAL B1: '+d.scenario_result.research_state+'\nBlockers: '+(d.scenario_result.blocking_reasons.join(', ')||'none')+'\nEFFECTIVE II: '+d.effective_research_state+' | Evidence: '+d.evidence_admission+' | Trading: '+d.trading_authorized)}catch(err){txt(el('result'),String(err))}};load();</script></body></html>''')
