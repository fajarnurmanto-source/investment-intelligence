# II fragmentation inventory — final disposition 10 November 2026

## Active state

| Scope | Classification | Disposition |
|---|---|---|
| `D:\Research\INVESTMENT RESEARCH\II\repository` | `TRACKED_SOURCE` | Sole Git worktree and editable source. |
| `D:\Research\INVESTMENT RESEARCH\II\runtime` | `LOCAL_RUNTIME` | Sole private runtime; excluded from Git. |
| `D:\Research\INVESTMENT RESEARCH\II\recovery` | `RECOVERY_ONLY` | Recovery bundles, manifests, and inactive quarantine; never a launcher target. |

The canonical root contains only `repository`, `runtime`, and `recovery`.

## Reconciliation and retirement

The three historical repositories with local changes were archived in full before retirement:

1. VS1 unborn `development` repository with staged README;
2. VS2 repository with nine staged/modified files;
3. VS3 repository with untracked hybrid overlay.

Their archives and SHA-256 values are recorded in `recovery\PROD_20261110_064105\legacy-deltas\legacy-deltas-manifest.json`.

The following legacy material was moved out of active locations into a recoverable, hash-verified quarantine:

| Category | Targets | Files | Bytes |
|---|---:|---:|---:|
| Historical `II_PRODUCT` tree | 1 | 18,271 | 193,406,503 |
| Pre-canonical folders under `II` | 8 | 95 | 769,204 |
| Root-level legacy II files on D | 213 | 213 | 1,287,782 |
| II packages/runs in C Downloads | 192 | 3,467 | 769,510,812 |
| II Temp directories | 11 | 0 | 0 |
| **Total** | **425** | **22,046** | **964,974,301** |

The complete before/after tree hashes and destinations are in `recovery\PROD_20261110_064105\legacy-retirement\quarantine-manifest.json`. After the move, no scoped II item remained in C Downloads/Temp, `II_PRODUCT` no longer existed at the workspace root, and no pre-canonical folder remained directly under the canonical II root.

## Held material

`D:\Research\INVESTMENT RESEARCH\99_BACK_UP` is retained because it contains R48/R48C/R48D verification evidence and recovery records. The new production recovery point and quarantine are also retained. These are recovery evidence, not active source or runtime.

Permanent deletion was not performed because the safety gate rejected deletion while the historical R48 record still stated that dependency classification had not been established. The recoverable move satisfies active-habitat consolidation without bypassing that control.
