# II VS1 candidate — controlled GitHub preparation

Synthetic-only Company Research vertical slice using the existing B1 evaluator. This folder is an isolated candidate, **not** the canonical II application or a production-ready release.

GitHub target after explicit approval: `fajarnurmanto-source/investment-intelligence` (PRIVATE).

No database, real issuer financial data, operational ledger, secrets or proprietary documents are included. Do not promote demo evidence or activate valuation/trading.

## Acceptance
- `python -m pip install -r requirements.txt`
- `python -m pytest -q`
- `python -m uvicorn app.main:app --host 127.0.0.1 --port 8810`

No git init/commit/push is authorized by this installer. Human approval needed for remote creation, commit and push.
