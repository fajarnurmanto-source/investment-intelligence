# II V12 — IDX/BEI Filing Evidence Intake (Phase 1)

## Scope
- A signed-in analyst uploads a lawfully obtained **PDF financial filing** from the [official BEI financial-report archive](https://www.idx.co.id/id/perusahaan-tercatat/laporan-keuangan-dan-tahunan/), enters the **exact HTTPS direct PDF URL** on the BEI domain, ticker, period and publication date, and attests internal-research rights.
- The application validates the allowed URL host and PDF header, stores the original document using the **existing immutable issuer importer**, SHA-256 checksum, user ownership and source provenance, and blocks duplicate bytes for the same account.
- This is **offline user-provided evidence**: NO crawling, API calls to IDX, PDF extraction, invented financial figures, market prices, or trading authority. A matching URL host **does not independently verify the uploaded bytes**; status is USER_ATTESTED, UNRECONCILED, LOW. Quality is DOCUMENT_ONLY, zero numeric periods.
- All saved records remain per-user; existing data, routes, credentials and production config are untouched. Normalized numeric ingestion is a separate reviewed step via existing CSV or supported IFRS XBRL.
- IDX/BEI Data Reference and Market Data are licensed products. A public filing is not authorization to run a commercial, automated data feed. See [IDX Data Services](https://www.idx.co.id/id/produk/layanan-data-bei/) and [BEI Terms](https://www.idx.co.id/id/syarat-penggunaan).

## Acceptance checks
- Auth and rights attestation required
- Direct HTTPS `.pdf` URL on exact official BEI/IDX host only; no URL fetch
- Reject off-domain, spoofed host/userinfo, HTTP, port, fragment, non-PDF extension, missing PDF header
- Identical PDF reimport blocked within an account
- Stored document: `DOCUMENT_ONLY`, `USER_ATTESTED`, `UNRECONCILED`, `INTERNAL_RESEARCH_ONLY`, zero numeric periods
- Existing Linux and Windows Tinker + PHPUnit CI pipelines must pass

## Not included / V12 Phase 2
- IDX XBRL taxonomy mapping must be backed by authoritative taxonomy specimens and strict context/unit checks. Unsupported taxonomies remain HOLD.
- Automated IDX reference feed may be added **only after matching source API access, usage rights and licensing are established**.
- PDF OCR is not a verified financial-data adapter.
