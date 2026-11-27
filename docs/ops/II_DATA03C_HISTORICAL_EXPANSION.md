# DATA-03C — pinned historical expansion
Production DATA-03A acceptance reported 2026-11-27: II_DATA03A_PRODUCTION=PASS, source hash 8f69ab70fbca4138c6daa3a47bb3dc75b805c9d160ed051308be63be7dd2f25e, 250 BBCA sessions, registered lineage, repeat UNCHANGED, Strategy Validation rendered. Preserve existing BBCA.
Source commit bc0ac7712ce5e46f1067349e13ab9f338883c6c4, 10 files independently inspected; each has 1355 source rows, 250 unique valid selected OHLCV rows, dates 2024-01-31 through 2025-02-21, constant listed shares within sample. Not proof of corporate action reconciliation. Same noncommercial rights attestation and no export or trading authority. No dataset bytes committed.
Preview: php artisan ii:bootstrap-idx-history --user=7 --rights-confirmed --ticker=ALL
Import: php artisan ii:bootstrap-idx-history --user=7 --rights-confirmed --ticker=ALL --import
Partial failures return nonzero; successful siblings remain; repeat is idempotent. Existing command defaults to BBCA. PR40 deferred. Actual batch runtime acceptance pending.
