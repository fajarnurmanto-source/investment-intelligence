# Investment Intelligence — Laravel Runtime

This is the application directory for **Investment Intelligence Local BYOK**. The product runs its PHP/Laravel web application on the owner's Windows machine, bound to loopback, with its own SQLite and APP_KEY. It is not a hosted data relay.

## Canonical paths
- Source: `laravel_app/`
- Installer scripts and tests: `../tools/installer/`
- Current architecture and rights policy: `../docs/ops/II_BYOK_LOCAL_MANDATORY_ARCHITECTURE_20261124.md`
- Source-code development sync: `../tools/sync-II.ps1` (Git only, not market-data synchronization)

## Credentials and licensed data
- Vendor keys are **not** included in the package, source, `.env.example` or Git.
- Windows BYOK keys use DPAPI CurrentUser and are stored as encrypted ciphertext in a private local SQLite row. The DPAPI key cannot be decrypted by another Windows logon identity. Reconnect when moving from the old APP_KEY-only format.
- Non-Windows tests use versioned Laravel Crypt ciphertext and never ship production keys.
- Restricted provider quotes are view-only in the local application and not admitted as portfolio data, CSV, raw archives or tradeable observations. Separate user-supplied and SEC/FRED sources retain their own independent rights.
- No order execution, central relay, multi-device sync or shared market-data key.

## Local development
Use a **disposable SQLite database** and a synthetic key. Never run tests, migrations or installer prototypes against a user's active runtime. Standard Laravel tests and the Windows installer synthetic fixtures run in GitHub Actions.

The installer remains under development: passing CI does not establish a signed Windows installer, a live provider entitlement or a verified full application migration/launch on an owner device.
