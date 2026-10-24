# II VS4 â€” Research Workspace (synthetic-only overlay)

This folder is an overlay for the existing Laravel VS3 application, NOT a production app or an independent deployable service. Copy the two files to the respective Laravel app locations and append `route_fragment.php` to `routes/web.php`. Reuse the VS3 `services.ii_engine.url` setting. No `vendor`, secrets, data, `.env`, databases or session files belong in this repository.

Local Windows operator evidence (2026-10-25): 10 Python regression tests PASS; FastAPI contract on 127.0.0.1:8811 PASS; DEMO2 and DEMO3 workspace GET PASS; reviewer session/CSRF POST PASS; Markdown report includes session note PASS; invalid ticker fail-closed PASS. This CI only recreates the PHP application shell, checks registration and compiles views: it does NOT replay the Windows E2E or grant production admission.

NOT_ADMITTED / HOLD / authority NONE / no valuation / no trading. No real evidence ingestion. No authenticated multiuser review; session notes are ephemeral. Filament is not yet installed.