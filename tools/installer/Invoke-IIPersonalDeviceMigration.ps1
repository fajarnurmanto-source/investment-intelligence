param(
    [Parameter(Mandatory)][string]$PackageRoot,
    [Parameter(Mandatory)][string]$DeviceDataRoot,
    [switch]$AllowUnsignedStagingForTest,
    [switch]$RunIsolatedMigration
)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'

# Deliberately gated: this is NOT production activation. Run only in disposable VM.
if (-not ($AllowUnsignedStagingForTest -and $RunIsolatedMigration)) { throw 'II_M08_MIGRATION_EXPLICIT_TEST_OPT_IN_REQUIRED' }
$verify = Join-Path $PSScriptRoot 'Test-IIPersonalStagingIntegrity.ps1'
$receipt = & $verify -PackageRoot $PackageRoot | ConvertFrom-Json
if ($receipt.status -ne 'STAGED_PAYLOAD_INTEGRITY_VERIFIED') { throw 'II_M08_PAYLOAD_UNVERIFIED' }
$package = (Resolve-Path -LiteralPath $PackageRoot).Path
$device = (Resolve-Path -LiteralPath $DeviceDataRoot).Path
$metaPath = Join-Path $device 'device.json'
$envPath = Join-Path $device '.env'
$dbPath = Join-Path $device 'database\ii.sqlite'
$php = Join-Path $package 'payload\server\bin\php.exe'
$app = Join-Path $package 'payload\app'
if (-not (Test-Path -LiteralPath $metaPath -PathType Leaf) -or
    -not (Test-Path -LiteralPath $envPath -PathType Leaf) -or
    -not (Test-Path -LiteralPath $dbPath -PathType Leaf)) { throw 'II_M08_DEVICE_NOT_INITIALIZED' }
$meta = Get-Content -LiteralPath $metaPath -Raw | ConvertFrom-Json
if ($meta.schema -ne 'ii.prt.device-bootstrap.v1' -or $meta.database_migrated -ne $false -or $meta.owner_enrolled -ne $false) {
    throw 'II_M08_DEVICE_STATE_NOT_PENDING'
}
# Reject the synthetic fixture and malformed/partial payload before mutation.
foreach ($path in @($php,(Join-Path $app 'artisan'),(Join-Path $app 'vendor\autoload.php'),
    (Join-Path $app 'bootstrap\app.php'),(Join-Path $app 'database\migrations'))) {
    if (-not (Test-Path -LiteralPath $path)) { throw 'II_M08_PACKAGED_PHP_OR_LARAVEL_MISSING' }
}
if ((Get-Item -LiteralPath $dbPath).Length -ne 0) { throw 'II_M08_EXPECTED_EMPTY_SQLITE_ONLY' }
$previous = @{}
foreach ($key in @('II_RUNTIME_ROOT','II_VENDOR_PATH','COMPOSER_VENDOR_DIR','APP_BASE_PATH',
    'APP_CONFIG_CACHE','APP_PACKAGES_CACHE','APP_SERVICES_CACHE','APP_ROUTES_CACHE','APP_EVENTS_CACHE')) {
    $previous[$key] = [Environment]::GetEnvironmentVariable($key,'Process')
}
try {
    [Environment]::SetEnvironmentVariable('II_RUNTIME_ROOT',$device,'Process')
    [Environment]::SetEnvironmentVariable('II_VENDOR_PATH',(Join-Path $app 'vendor'),'Process')
    [Environment]::SetEnvironmentVariable('COMPOSER_VENDOR_DIR',(Join-Path $app 'vendor'),'Process')
    [Environment]::SetEnvironmentVariable('APP_BASE_PATH',$app,'Process')
    foreach ($pair in @(@('APP_CONFIG_CACHE','config.php'),@('APP_PACKAGES_CACHE','packages.php'),
        @('APP_SERVICES_CACHE','services.php'),@('APP_ROUTES_CACHE','routes.php'),
        @('APP_EVENTS_CACHE','events.php'))) {
        [Environment]::SetEnvironmentVariable($pair[0],(Join-Path $device ('bootstrap-cache\'+$pair[1])),'Process')
    }
    Push-Location $app
    try {
        & $php artisan migrate --force --no-interaction
        if ($LASTEXITCODE -ne 0) { throw 'II_M08_MIGRATION_COMMAND_FAILED' }
        & $php artisan migrate:status --no-interaction
        if ($LASTEXITCODE -ne 0) { throw 'II_M08_MIGRATION_VERIFICATION_FAILED' }
    } finally { Pop-Location }
    $meta.database_migrated=$true
    $meta.activation_status='PENDING_OWNER_ENROLLMENT'
    $tmp=$metaPath+'.pending'
    [IO.File]::WriteAllText($tmp,($meta | ConvertTo-Json -Depth 8),[Text.UTF8Encoding]::new($false))
    [IO.File]::Move($tmp,$metaPath,$true)
    Write-Output '{"status":"PENDING_OWNER_ENROLLMENT","database_migrated":true,"login_ready":false}'
} finally {
    foreach($key in $previous.Keys){[Environment]::SetEnvironmentVariable($key,$previous[$key],'Process')}
}
