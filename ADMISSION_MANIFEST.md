# GitHub candidate admission manifest (allowlist only)

| File | Reason |
|---|---|
| README.md | Candidate description |
| GITHUB_CANDIDATE.md | Candidate boundaries |
| requirements.txt | Synthetic runtime dependencies |
| app/main.py | FastAPI vertical slice |
| legacy/b1.py | B1 synthetic evaluator included by package |
| tests/test_product.py | Synthetic tests |
| .github/workflows/ci.yml | Deterministic CI |
| .gitignore | Local exclusion rules |

NOT APPROVED for GitHub yet. No II runtime files, DuckDB, SQLite, secrets, snapshots or real evidence admitted.
