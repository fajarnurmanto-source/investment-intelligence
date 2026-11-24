Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$initializer = Join-Path (Split-Path -Parent $PSScriptRoot) 'Initialize-IIPersonalDeviceState.ps1'
$root = Join-Path ([IO.Path]::GetTempPath()) ('ii-prt-device-'+[Guid]::NewGuid().ToString('N'))
$package = Join-Path $root 'package'
$payload = Join-Path $package 'payload'
$first = Join-Path $root 'device-a'
$second = Join-Path $root 'device-b'
try {
    New-Item -ItemType Directory -Path (Join-Path $payload 'app') -Force | Out-Null
    $entry = Join-Path $payload 'app\artisan'
    [IO.File]::WriteAllText($entry,'synthetic launcher')
    $hash = (Get-FileHash -LiteralPath $entry -Algorithm SHA256).Hash.ToLowerInvariant()
    $manifest = [ordered]@{
        schema='ii.prt.staged-payload.v1'
        status='STAGED_UNSIGNED_NOT_INSTALLABLE'
        signed=$false
        includes_secrets=$false
        file_count=1
        files=@([ordered]@{path='app/artisan';bytes=(Get-Item $entry).Length;sha256=$hash})
    }
    $path = Join-Path $package 'payload-manifest.json'
    [IO.File]::WriteAllText($path,($manifest | ConvertTo-Json -Depth 8),[Text.UTF8Encoding]::new($false))
    $manifestHash = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    [IO.File]::WriteAllText((Join-Path $package 'payload-manifest.sha256'),$manifestHash,[Text.Encoding]::ASCII)

    $denied = $false
    try { & $initializer -PackageRoot $package -DeviceDataRoot $first | Out-Null } catch { $denied=$true }
    if (-not $denied -or (Test-Path -LiteralPath $first)) { throw 'UNSIGNED_STAGE_GUARD_FAILED' }

    $a = (& $initializer -PackageRoot $package -DeviceDataRoot $first -AllowUnsignedStagingForTest | ConvertFrom-Json)
    $b = (& $initializer -PackageRoot $package -DeviceDataRoot $second -AllowUnsignedStagingForTest | ConvertFrom-Json)
    if ($a.status -ne 'PENDING_DATABASE_MIGRATION_AND_OWNER_ENROLLMENT') { throw 'BOOTSTRAP_FALSE_ACTIVATION' }
    if ($a.database_migrated -ne $false -or $a.login_ready -ne $false) { throw 'BOOTSTRAP_UNVERIFIED_CLAIM' }
    if ($a.device_id -eq $b.device_id) { throw 'DEVICE_ID_NOT_UNIQUE' }
    foreach ($deviceRoot in @($first,$second)) {
        $protectedAcl = Get-Acl -LiteralPath $deviceRoot
        if (-not $protectedAcl.AreAccessRulesProtected) { throw 'DEVICE_DACL_INHERITANCE_NOT_PROTECTED' }
        $allowedSids = @(
            [Security.Principal.WindowsIdentity]::GetCurrent().User.Value,
            'S-1-5-18'
        )
        foreach ($access in @($protectedAcl.Access)) {
            if ($access.AccessControlType -eq [Security.AccessControl.AccessControlType]::Allow) {
                $sid = $access.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
                if ($sid -notin $allowedSids) { throw 'DEVICE_DACL_UNEXPECTED_PRINCIPAL' }
            }
        }
        $secretAcl = Get-Acl -LiteralPath (Join-Path $deviceRoot '.env')
        if (-not $secretAcl.AreAccessRulesProtected -and -not $protectedAcl.AreAccessRulesProtected) {
            throw 'DEVICE_SECRET_ACL_NOT_RESTRICTED'
        }
    }
    $envA = Get-Content -LiteralPath (Join-Path $first '.env') -Raw
    $envB = Get-Content -LiteralPath (Join-Path $second '.env') -Raw
    $keyA = [regex]::Match($envA,'(?m)^APP_KEY=(base64:[A-Za-z0-9+/=]+)\r?$').Groups[1].Value
    $keyB = [regex]::Match($envB,'(?m)^APP_KEY=(base64:[A-Za-z0-9+/=]+)\r?$').Groups[1].Value
    if ($keyA.Length -lt 40 -or $keyB.Length -lt 40 -or $keyA -eq $keyB) { throw 'DEVICE_KEYS_NOT_DISTINCT' }
    if ($a.PSObject.Properties.Name -contains 'APP_KEY' -or $envA.Contains('test-provider-key')) { throw 'BOOTSTRAP_KEY_EXPOSURE' }
    foreach ($value in @($envA,$envB)) {
        if ($value -match '(?i)II_IDXEDGE_AUTO_SYNC|II_IDXAPI_KEY|II_SHARED_BYOK_KEY') {
            throw 'DEVICE_LEGACY_SHARED_FEED_ENV_PRESENT'
        }
        if ($value -notmatch '(?m)^DB_CONNECTION=sqlite\r?
    foreach ($r in @($first,$second)) {
        $db = Join-Path $r 'database\ii.sqlite'
        if (-not (Test-Path -LiteralPath $db) -or (Get-Item $db).Length -ne 0) { throw 'DEVICE_DATABASE_STATE_INVALID' }
        $meta = Get-Content -LiteralPath (Join-Path $r 'device.json') -Raw | ConvertFrom-Json
        if ($meta.database_migrated -ne $false -or $meta.owner_enrolled -ne $false) { throw 'DEVICE_META_FALSE_ACCEPTANCE' }
    }

    # Migration must fail closed against incomplete synthetic staging; no
    # mutation of either device's private key or database is permitted.
    $migrate = Join-Path (Split-Path -Parent $PSScriptRoot) 'Invoke-IIPersonalDeviceMigration.ps1'
    $beforeMeta = Get-Content -LiteralPath (Join-Path $first 'device.json') -Raw
    $beforeEnv = Get-Content -LiteralPath (Join-Path $first '.env') -Raw
    $migrationDenied = $false
    try { & $migrate -PackageRoot $package -DeviceDataRoot $first | Out-Null } catch { $migrationDenied=$true }
    if (-not $migrationDenied) { throw 'MIGRATION_NO_OPT_IN_NOT_BLOCKED' }
    $missingPhpDenied = $false
    try {
        & $migrate -PackageRoot $package -DeviceDataRoot $first -AllowUnsignedStagingForTest -RunIsolatedMigration | Out-Null
    } catch { $missingPhpDenied=$true }
    if (-not $missingPhpDenied) { throw 'MIGRATION_MISSING_RUNTIME_NOT_BLOCKED' }
    if ((Get-Content -LiteralPath (Join-Path $first 'device.json') -Raw) -ne $beforeMeta -or
        (Get-Content -LiteralPath (Join-Path $first '.env') -Raw) -ne $beforeEnv -or
        (Get-Item -LiteralPath (Join-Path $first 'database\ii.sqlite')).Length -ne 0) {
        throw 'MIGRATION_DENIED_BUT_DEVICE_MUTATED'
    }

    $deniedRepeat = $false
    try { & $initializer -PackageRoot $package -DeviceDataRoot $first -AllowUnsignedStagingForTest | Out-Null } catch { $deniedRepeat=$true }
    if (-not $deniedRepeat) { throw 'DEVICE_REINITIALIZATION_NOT_BLOCKED' }
    if ((Get-Content -LiteralPath (Join-Path $first '.env') -Raw) -ne $envA) { throw 'DEVICE_EXISTING_KEY_MODIFIED' }

    [IO.File]::AppendAllText($entry,'tamper')
    $deniedTamper = $false
    $third = Join-Path $root 'device-c'
    try { & $initializer -PackageRoot $package -DeviceDataRoot $third -AllowUnsignedStagingForTest | Out-Null } catch { $deniedTamper=$true }
    if (-not $deniedTamper -or (Test-Path -LiteralPath $third)) { throw 'DEVICE_TAMPERED_PACKAGE_ACCEPTED' }
    Write-Host 'II_PRT_M08_DEVICE_BOOTSTRAP_TEST=PASS'
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
 -or
            $value -notmatch '(?m)^APP_DEBUG=false\r?
    foreach ($r in @($first,$second)) {
        $db = Join-Path $r 'database\ii.sqlite'
        if (-not (Test-Path -LiteralPath $db) -or (Get-Item $db).Length -ne 0) { throw 'DEVICE_DATABASE_STATE_INVALID' }
        $meta = Get-Content -LiteralPath (Join-Path $r 'device.json') -Raw | ConvertFrom-Json
        if ($meta.database_migrated -ne $false -or $meta.owner_enrolled -ne $false) { throw 'DEVICE_META_FALSE_ACCEPTANCE' }
    }

    # Migration must fail closed against incomplete synthetic staging; no
    # mutation of either device's private key or database is permitted.
    $migrate = Join-Path (Split-Path -Parent $PSScriptRoot) 'Invoke-IIPersonalDeviceMigration.ps1'
    $beforeMeta = Get-Content -LiteralPath (Join-Path $first 'device.json') -Raw
    $beforeEnv = Get-Content -LiteralPath (Join-Path $first '.env') -Raw
    $migrationDenied = $false
    try { & $migrate -PackageRoot $package -DeviceDataRoot $first | Out-Null } catch { $migrationDenied=$true }
    if (-not $migrationDenied) { throw 'MIGRATION_NO_OPT_IN_NOT_BLOCKED' }
    $missingPhpDenied = $false
    try {
        & $migrate -PackageRoot $package -DeviceDataRoot $first -AllowUnsignedStagingForTest -RunIsolatedMigration | Out-Null
    } catch { $missingPhpDenied=$true }
    if (-not $missingPhpDenied) { throw 'MIGRATION_MISSING_RUNTIME_NOT_BLOCKED' }
    if ((Get-Content -LiteralPath (Join-Path $first 'device.json') -Raw) -ne $beforeMeta -or
        (Get-Content -LiteralPath (Join-Path $first '.env') -Raw) -ne $beforeEnv -or
        (Get-Item -LiteralPath (Join-Path $first 'database\ii.sqlite')).Length -ne 0) {
        throw 'MIGRATION_DENIED_BUT_DEVICE_MUTATED'
    }

    $deniedRepeat = $false
    try { & $initializer -PackageRoot $package -DeviceDataRoot $first -AllowUnsignedStagingForTest | Out-Null } catch { $deniedRepeat=$true }
    if (-not $deniedRepeat) { throw 'DEVICE_REINITIALIZATION_NOT_BLOCKED' }
    if ((Get-Content -LiteralPath (Join-Path $first '.env') -Raw) -ne $envA) { throw 'DEVICE_EXISTING_KEY_MODIFIED' }

    [IO.File]::AppendAllText($entry,'tamper')
    $deniedTamper = $false
    $third = Join-Path $root 'device-c'
    try { & $initializer -PackageRoot $package -DeviceDataRoot $third -AllowUnsignedStagingForTest | Out-Null } catch { $deniedTamper=$true }
    if (-not $deniedTamper -or (Test-Path -LiteralPath $third)) { throw 'DEVICE_TAMPERED_PACKAGE_ACCEPTED' }
    Write-Host 'II_PRT_M08_DEVICE_BOOTSTRAP_TEST=PASS'
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
) {
            throw 'DEVICE_LOCAL_ENV_GUARD_MISSING'
        }
    }
    foreach ($r in @($first,$second)) {
        $db = Join-Path $r 'database\ii.sqlite'
        if (-not (Test-Path -LiteralPath $db) -or (Get-Item $db).Length -ne 0) { throw 'DEVICE_DATABASE_STATE_INVALID' }
        $meta = Get-Content -LiteralPath (Join-Path $r 'device.json') -Raw | ConvertFrom-Json
        if ($meta.database_migrated -ne $false -or $meta.owner_enrolled -ne $false) { throw 'DEVICE_META_FALSE_ACCEPTANCE' }
    }

    # Migration must fail closed against incomplete synthetic staging; no
    # mutation of either device's private key or database is permitted.
    $migrate = Join-Path (Split-Path -Parent $PSScriptRoot) 'Invoke-IIPersonalDeviceMigration.ps1'
    $beforeMeta = Get-Content -LiteralPath (Join-Path $first 'device.json') -Raw
    $beforeEnv = Get-Content -LiteralPath (Join-Path $first '.env') -Raw
    $migrationDenied = $false
    try { & $migrate -PackageRoot $package -DeviceDataRoot $first | Out-Null } catch { $migrationDenied=$true }
    if (-not $migrationDenied) { throw 'MIGRATION_NO_OPT_IN_NOT_BLOCKED' }
    $missingPhpDenied = $false
    try {
        & $migrate -PackageRoot $package -DeviceDataRoot $first -AllowUnsignedStagingForTest -RunIsolatedMigration | Out-Null
    } catch { $missingPhpDenied=$true }
    if (-not $missingPhpDenied) { throw 'MIGRATION_MISSING_RUNTIME_NOT_BLOCKED' }
    if ((Get-Content -LiteralPath (Join-Path $first 'device.json') -Raw) -ne $beforeMeta -or
        (Get-Content -LiteralPath (Join-Path $first '.env') -Raw) -ne $beforeEnv -or
        (Get-Item -LiteralPath (Join-Path $first 'database\ii.sqlite')).Length -ne 0) {
        throw 'MIGRATION_DENIED_BUT_DEVICE_MUTATED'
    }

    $deniedRepeat = $false
    try { & $initializer -PackageRoot $package -DeviceDataRoot $first -AllowUnsignedStagingForTest | Out-Null } catch { $deniedRepeat=$true }
    if (-not $deniedRepeat) { throw 'DEVICE_REINITIALIZATION_NOT_BLOCKED' }
    if ((Get-Content -LiteralPath (Join-Path $first '.env') -Raw) -ne $envA) { throw 'DEVICE_EXISTING_KEY_MODIFIED' }

    [IO.File]::AppendAllText($entry,'tamper')
    $deniedTamper = $false
    $third = Join-Path $root 'device-c'
    try { & $initializer -PackageRoot $package -DeviceDataRoot $third -AllowUnsignedStagingForTest | Out-Null } catch { $deniedTamper=$true }
    if (-not $deniedTamper -or (Test-Path -LiteralPath $third)) { throw 'DEVICE_TAMPERED_PACKAGE_ACCEPTED' }
    Write-Host 'II_PRT_M08_DEVICE_BOOTSTRAP_TEST=PASS'
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
