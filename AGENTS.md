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
