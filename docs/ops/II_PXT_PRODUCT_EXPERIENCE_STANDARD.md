# II — Product Experience & Brand Transformation
Decision: 28 November 2026. User approved the light institutional design concept (point 5), and explicitly requested plain Indonesian and information curation throughout II. This is a product requirement, not evidence of canonical roadmap reconciliation or completed redesign.

## Visual direction
Light default, institutional navy #142A40, teal #147D78 for primary actions, gold #C6A36B sparingly for brand, background #F4F6F9, white surfaces, border #DCE2E9, text #172535. Semantic gain/loss colors independent of brand, with labels/signs as well as color. Typography: Inter if available locally, system fallback, tabular figures. Consistent SVG icons with accessible labels; no decorative glyphs or remote assets. Logo concept and exact font licensing remain subject to verification. Prefer quiet, spacious layouts, proportional cards, responsive tables and task-oriented navigation.

## Content hierarchy
1. Primary: answer the user's current question, date, essential context, one clear next action.
2. Supporting: relevant comparisons, tables and explanations, shown when useful.
3. Details on demand: methodology, data coverage, evidence, uncertainty, source and checksum.
4. Diagnostics only: internal codes, class names, framework labels, commit IDs, stack traces and developer logs. Never expose secrets. Do not hide material limitations, cost, rights or risk in diagnostics.

## Language
Indonesian, natural and concise. Use established finance terms with first-use explanation when needed; do not replace precise concepts with misleading synonyms. One label per concept across menus, forms, charts and reports. Buttons use action verbs; errors explain what happened and what can be done. Do not display raw status enums, roadmap names, internal gate counts or implementation architecture on primary surfaces.
Examples: Market Breadth → Arah pergerakan saham; Previous → Harga sebelumnya (kolom Previous); missing data → Data belum tersedia (not zero); unverified units → Satuan belum dipastikan; provenance → Sumber dan riwayat data; entitlement → Hak akses data; discovery → Cari instrumen; ingestion → Masukkan data; receipt → Catatan pengambilan data; replay → Periksa ulang data yang sama. Distinguish evidence review from accuracy verification; never turn unverified into verified.

## Workflow
Proposed seven groups: Beranda, Pasar, Cari saham, Perusahaan, Portofolio, Riset saya, Pengaturan. Preserve existing capability and permissions via subnavigation. Prioritize a full user journey: choose source, analyze, understand, inspect a company, record findings. No terminal or developer vocabulary required for ordinary use. Avoid duplicate help and repeated warnings; put material limitations adjacent to results.

## Acceptance
For each changed workspace: verify normal/empty/error/limited-data states, task completion, keyboard focus, contrast, desktop and 390px mobile, no page-wide horizontal overflow, no technical codes in primary content, correct units and dates, no loss of functions or authorization. Use existing tests and targeted browser evidence; no claims of completion without execution. Local-only and rights controls remain unchanged. Roll out light theme incrementally to avoid unreadable legacy hardcoded colors; audit all existing screens before switching global default. PXT is concurrent with DATA05 and must be fused into canonical roadmap when reconciled.
