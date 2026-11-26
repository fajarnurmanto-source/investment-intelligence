# DATA-02H integration checkpoint — 2026-11-26

Stable development: fc2568698a8167d223a0b61af8808a1deccefc1f (PR 36 merged; Laravel CI 45736086659 and BYOK CI 45736086704 passed).

PR 37 retargeted to development. Prior stacked candidate 1358520390631b468fe0f4b2cbf9715b3ec1eb01 passed BYOK CI 45736198488 only; Laravel CI triggers on development-targeted pull requests. This commit requests a fresh candidate run. Require both workflows on the latest candidate before merge.

Existing local production habitat: repository sibling runtime/laravel (database/ii.sqlite, .env, storage, vendor, bootstrap-cache), runtime/server. Existing stop-ii.ps1, New-IIRecoveryPoint.ps1, Deploy-IIProduction.ps1 and status-ii.ps1 should be reused. Stop managed processes before recovery point and before fast-forward; preserve APP_KEY and OS identity; no reset, no destructive rollback. Deploy only an explicitly verified merged commit. Keep market feed disabled for initial acceptance.

Remaining gates: final CI/merge, local backup/deploy and runtime verification, entitlement and real-data acceptance. Read-only workspace, calendar-gap heuristic, Windows worker timing and UI configuration limitations remain. No local change performed.

User requested checkpoint records in repository rather than repeated capsules in chat.