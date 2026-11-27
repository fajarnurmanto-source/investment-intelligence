# Mandatory development protocol

Approved 2026-11-26. Applies to all II changes; Laravel-only runtime.

- Start by verifying repository access, branch, HEAD, PR and CI. Diagnose failures from actual error evidence; never claim an outage without checking. Retry transient errors within available execution; verify uncertain writes before repeating.
- Execute a complete vertical slice (persistence, services, authorization, routes, UI, tests) within the current task where possible. Reuse native Laravel and existing packages first. Do not stop at inspection when actionable work remains.
- Prioritize P0 security/data integrity, P1 operational reliability, P2 improvements. Keep production disabled or unchanged until gates pass.
- For every incident record observed failure, root cause or uncertainty, correction, regression test and prevention. Test positive, negative, boundary and state transitions independently. Preserve security controls rather than weakening tests or middleware.
- Prefer coherent multi-file changes; avoid gratuitous CI triggers. Run focused tests then full Linux/Windows regression. Read failed logs, repair and recheck. Do not merge failed, pending or unverified candidates.
- Explicitly distinguish committed, integrated, regression-passed, merged, deployed and accepted. Record evidence in repository. Never equate synthetic provider tests with live entitlement or local runtime acceptance.
- Credentials stay local and encrypted. No secrets in source, queue, logs, session or reports. Authorization changes must invalidate stale work; failures must fail closed.
- Continue related steps autonomously within available tools and current session until a real blocker, external authorization or acceptance requiring user action. No background execution claims. Report exact blocker and evidence, not speculation.
- Preserve existing baseline, user data and APP_KEY; use verified backup and controlled deployment. No destructive resets or silent expansion of data rights.

Current P0: authorization generation; unify CLI/UI credential changes; failure and role isolation tests. P1: discovery freshness/quota, integration gate, deployment. P2: live provider acceptance.

## Hybrid data resilience mandate (approved 2026-11-27)
- Local-first, provider-agnostic and rights-aware. Separate historical, direct and discovery paths; seven benchmark repositories are references, not evidence of independent data supply or license.
- Quality before availability: distinguish structural validity, lineage integrity, independent corroboration, adjustment basis, freshness, entitlement and admission. No fabricated values or automatic promotion of stale or disputed data. Preserve last usable archive; managed corrupt/unregistered snapshots must not displace valid history.
- Evaluate upstream independence, costs, storage and retention rights, calendar/identity/corporate actions before routing or fallback. Unknown or incompatible data remains research-only; no trading authority. No scraping that violates terms.
- Measure continuity per capability, not global provider availability; keep offline historical research operational without claiming current signals. Test offline, outage, partial failure, conflict and recovery. Real provider and local acceptance remain separate from synthetic CI.

## Connector fusion and zero-cost mandate (approved 2026-11-27)
- Reuse before rebuild: inspect pinned upstream code and license; adapt compatible capabilities into Laravel, preserve attribution and required license notices. Repository license is not data entitlement.
- Data acquisition and subscription budget IDR 0; no paid upgrades, trials with rollover or unknown-cost activation. Prefer authorized public historical files and local continuity. No unsupported access or independent accuracy claims.
- Validate real source bytes, coverage, identity and share basis; reject conflicting or malformed series. Preserve existing accepted data and distinguish actual source acquisition, synthetic CI and production runtime acceptance.

## Autonomous incident closure mandate (approved 2026-11-27)
- A manual developer correction is not durable incident closure. For every observed actual runtime failure record detection, root cause or uncertainty, safe automated recovery or containment, regression reproducer, recovery evidence and remaining human-only prerequisites. Do not claim autonomous remediation merely from a successful manual repair.
- Reuse native Laravel mechanisms for bounded retry, locking, versioned recovery, health checks and safe fallback. Never silently alter permissions, costs, credentials, prices, admission or user data. External entitlement changes require authorized intervention; II must diagnose, contain and remain usable where possible.
- Test failure, recovery, repeated execution and return to normal; distinguish degraded research availability from successful fresh acquisition. Keep production acceptance evidence separate from synthetic CI.
