from fastapi.testclient import TestClient
from app.main import app
client=TestClient(app)
def test_v2_universe():
 r=client.get('/api/v2/companies'); assert r.status_code==200; assert len(r.json())==2; assert all(x['admission']=='NOT_ADMITTED' for x in r.json())
def test_v2_history_provenance():
 d=client.get('/api/v2/companies/DEMO2/research').json(); assert len(d['history'])==3; assert d['history'][-1]['net_margin_pct']==18; assert d['provenance']['source_type']=='GENERATED_DEMO'; assert d['effective_decision']=='HOLD'
def test_v2_real_issuer_disallowed():
 assert client.get('/api/v2/companies/ANTM/research').status_code==404
def test_v2_mutation_not_exposed():
 assert client.post('/api/v2/companies/DEMO2/research',json={'admission':'ADMITTED'}).status_code==405
def test_v2_html():
 s=client.get('/').text; assert 'Historical financial metrics' in s and 'Provenance & quality' in s and 'Evaluate scenario' in s
