from fastapi.testclient import TestClient
from app.main import app
client = TestClient(app)

def test_company_workspace():
    r=client.get('/api/companies');assert r.status_code==200 and len(r.json())==2
    assert client.get('/api/companies/DEMO2').json()['effective_decision']=='HOLD'
    assert client.get('/api/companies/ANTM').status_code==404

def test_falsification():
    r=client.post('/api/companies/DEMO2/scenario',json={'metric':'Net_Margin_Pct','observed_value':8,'minimum_value':15,'falsification_below':10})
    assert r.status_code==200
    d=r.json();assert d['scenario_result']['thesis_falsified'] is True
    assert d['effective_research_state']=='HOLD' and not d['trading_authorized']

def test_loosened_threshold_cannot_grant_admission():
    r=client.post('/api/companies/DEMO2/scenario',json={'metric':'Net_Margin_Pct','observed_value':8,'minimum_value':7,'falsification_below':5})
    d=r.json();assert d['scenario_result']['research_state']=='RESEARCH_REVIEW_READY'
    assert d['evidence_admission']=='NOT_ADMITTED' and d['effective_research_state']=='HOLD'

def test_validation():
    assert client.post('/api/companies/DEMO2/scenario',json={'metric':'Net_Margin_Pct','observed_value':8,'minimum_value':7,'falsification_below':10}).status_code==422
    assert client.post('/api/companies/DEMO2/scenario',json={'metric':'Price','observed_value':8,'minimum_value':7,'falsification_below':5}).status_code==422

def test_dashboard():
    assert 'Company Research Workstation' in client.get('/').text
