# II scoped fragmentation inventory — 9 November 2026

## Ownership decision

| Scope | Classification | Decision |
|---|---|---|
| `D:\Research\INVESTMENT RESEARCH\II\repository` | `TRACKED_SOURCE` | The only active Git worktree and editable source of truth. |
| `D:\Research\INVESTMENT RESEARCH\II\runtime\laravel` | `LOCAL_RUNTIME` | Untracked environment, database, storage, logs, cache, sessions, views, and Composer vendor tree. |
| `D:\Research\INVESTMENT RESEARCH\II\web`, `intelligence`, `contracts`, `docs`, `evidence`, `launcher`, `tests`, `tools` | `PROTECTED` | Pre-canonical R30-era material; retained for rollback, not active source. |
| Eight older repositories under `II_PRODUCT\GITHUB_CANDIDATES` and `II_PRODUCT\GIT_DELIVERY` | `ARCHIVED` or `PROTECTED` | Clean historical repositories are archived in place. Three repositories with local changes are protected and untouched. |
| `II_PRODUCT\HYBRID_VS3` and `HYBRID_VS5_BACKUPS` through `HYBRID_VS11_BACKUPS` | `PROTECTED` | Historical Laravel overlays/backups; not launcher targets and not independently editable active source. |
| 192 top-level II items in `C:\Users\fajar\Downloads` | `PROTECTED` | Packages, test runs, launcher generations, and roadmap inputs. No deletion or move performed. |
| 11 II directories in `C:\Users\fajar\AppData\Local\Temp` | `PROTECTED` | Historical patches/E2E outputs. No deletion performed. |
| `C:\` legacy/retirement scope | `PROTECTED` | R44/R49 cleanup remains a separate admission; no C file changed. |
| Ubuntu-24.04 `/home` | `ARCHIVED/NONE_ACTIVE` | Read-only search found SIFI repositories only; no II repository or source root. |

## Protected repositories with local changes

1. `...\VS1_20261024_160639_71ea24\repo` — unborn `development`, staged `README.md`.
2. `...\II_VS2_GIT_422fdc366c864b998e229766098f4c8a` — nine staged/modified VS2 files.
3. `...\VS3_20261024_214634_a7b47ce8\repo` — untracked `hybrid_vs3/`.

These deltas were not copied, reset, committed, or overwritten. They are historical protected work, not active source.

## Move verification

The cutover worktree moved from `II_PRODUCT\GIT_DELIVERY\VS5_20261025_134614_e02691cc\repo` to the canonical path without copying. Pre-move and post-move Git history contained cutover commit `4f8e51787f5adf87b2d3f17fbdefe87028e0933f`; after synchronizing `development`, the feature branch merge commit was `55b0bf1`. The original source directory no longer contains a worktree.

Runtime relocation was verified once before/after movement:

- `.env` SHA-256: `1913C8DC9DF82AFDB049416E0EAB75F50A2CE84B78E9CBCC3105A9FB1C56F6EA`;
- FRED SQLite SHA-256: `7E78B09CA1A051E0671F551CACAE4D6DD752C40D8812357F09A03E8421EFFC8D`;
- Composer `installed.json` SHA-256: `7A5D68F260B2CE0E3B0DE94E1C565147864955A3D6B38C234EF5B0521685C773`;
- seven storage files and two bootstrap-cache files matched their original hashes.

There are no `UNKNOWN` active-source candidates in the scoped inventory. Protected historical material is intentionally retained and is not a second active habitat.
