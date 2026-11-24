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
    $envA = Get-Content -LiteralPath (Join-Path $first '.env') -Raw
    $envB = Get-Content -LiteralPath (Join-Path $second '.env') -Raw
    $keyA = [regex]::Match($envA,'(?m)^APP_KEY=(base64:[A-Za-z0-9+/=]+)$').Groups[1].Value
    $keyB = [regex]::Match($envB,'(?m)^APP_KEY=(base64:[A-Za-z0-9+/=]+)$').Groups[1].Value
    if ($keyA.Length -lt 40 -or $keyB.Length -lt 40 -or $keyA -eq $keyB) { throw 'DEVICE_KEYS_NOT_DISTINCT' }
    if ($a.PSObject.Properties.Name -contains 'APP_KEY' -or $envA.Contains('test-provider-key')) { throw 'BOOTSTRAP_KEY_EXPOSURE' }
    foreach ($r in @($first,$second)) {
        $db = Join-Path $r 'database\ii.sqlite'
        if (-not (Test-Path -LiteralPath $db) -or (Get-Item $db).Length -ne 0) { throw 'DEVICE_DATABASE_STATE_INVALID' }
        $meta = Get-Content -LiteralPath (Join-Path $r 'device.json') -Raw | ConvertFrom-Json
        if ($meta.database_migrated -ne $false -or $meta.owner_enrolled -ne $false) { throw 'DEVICE_META_FALSE_ACCEPTANCE' }
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
