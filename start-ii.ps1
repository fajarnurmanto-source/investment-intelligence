param(
    [ValidateRange(1024, 65535)]
    [int]$Port = 8810,
    [switch]$SkipMigrate
)

$ErrorActionPreference = 'Stop'
$repoRoot = $PSScriptRoot
$habitatRoot = Split-Path -Parent $repoRoot
$appRoot = Join-Path $PSScriptRoot 'laravel_app'
$runtimeRoot = Join-Path $habitatRoot 'runtime\laravel'
$storageRoot = Join-Path $runtimeRoot 'storage'
$bootstrapCacheRoot = Join-Path $runtimeRoot 'bootstrap-cache'
$databaseRoot = Join-Path $runtimeRoot 'database'
$databasePath = Join-Path $databaseRoot 'ii.sqlite'
$environmentPath = Join-Path $runtimeRoot '.env'
$vendorRoot = Join-Path $runtimeRoot 'vendor'

if (-not (Test-Path -LiteralPath (Join-Path $appRoot 'artisan'))) {
    throw 'Laravel application was not found.'
}
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP 8.3 or newer is required.'
}

$runtimeDirectories = @(
    $runtimeRoot,
    $bootstrapCacheRoot,
    $databaseRoot,
    (Join-Path $storageRoot 'app\private'),
    (Join-Path $storageRoot 'app\public'),
    (Join-Path $storageRoot 'framework\cache\data'),
    (Join-Path $storageRoot 'framework\sessions'),
    (Join-Path $storageRoot 'framework\testing'),
    (Join-Path $storageRoot 'framework\views'),
    (Join-Path $storageRoot 'logs')
)
foreach ($directory in $runtimeDirectories) {
    if (-not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }
}

if (-not (Test-Path -LiteralPath $environmentPath)) {
    Copy-Item -LiteralPath (Join-Path $appRoot '.env.example') -Destination $environmentPath
}
if (-not (Test-Path -LiteralPath $databasePath)) {
    New-Item -ItemType File -Path $databasePath | Out-Null
}

function Set-EnvironmentValue {
    param([string]$Path, [string]$Name, [string]$Value)

    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) { $lines.Add($line) }
    $updated = $false
    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match "^$([regex]::Escape($Name))=") {
            $lines[$index] = "$Name=$Value"
            $updated = $true
            break
        }
    }
    if (-not $updated) { $lines.Add("$Name=$Value") }
    [System.IO.File]::WriteAllLines($Path, $lines, [System.Text.UTF8Encoding]::new($false))
}

$databaseForLaravel = $databasePath.Replace('\', '/')
Set-EnvironmentValue -Path $environmentPath -Name 'DB_CONNECTION' -Value 'sqlite'
Set-EnvironmentValue -Path $environmentPath -Name 'DB_DATABASE' -Value ('"' + $databaseForLaravel + '"')
Set-EnvironmentValue -Path $environmentPath -Name 'APP_URL' -Value "http://127.0.0.1:$Port"

$env:II_RUNTIME_ROOT = $runtimeRoot
$env:II_VENDOR_PATH = $vendorRoot
$env:COMPOSER_VENDOR_DIR = $vendorRoot
$env:APP_BASE_PATH = $appRoot
$env:APP_SERVICES_CACHE = Join-Path $bootstrapCacheRoot 'services.php'
$env:APP_PACKAGES_CACHE = Join-Path $bootstrapCacheRoot 'packages.php'
$env:APP_CONFIG_CACHE = Join-Path $bootstrapCacheRoot 'config.php'
$env:APP_ROUTES_CACHE = Join-Path $bootstrapCacheRoot 'routes.php'
$env:APP_EVENTS_CACHE = Join-Path $bootstrapCacheRoot 'events.php'

Push-Location $appRoot
try {
    if (-not (Test-Path -LiteralPath (Join-Path $vendorRoot 'autoload.php'))) {
        & composer install --no-interaction --prefer-dist
        if ($LASTEXITCODE -ne 0) { throw 'Composer install failed.' }
    }
    $appKey = Select-String -LiteralPath $environmentPath -Pattern '^APP_KEY=.+$' -Quiet
    if (-not $appKey) {
        & php artisan key:generate --force
        if ($LASTEXITCODE -ne 0) { throw 'Laravel key generation failed.' }
    }
    & php artisan optimize:clear
    if ($LASTEXITCODE -ne 0) { throw 'Laravel cache reset failed.' }
    if (-not $SkipMigrate) {
        & php artisan migrate --force
        if ($LASTEXITCODE -ne 0) { throw 'Laravel migration failed.' }
    }

    $queue = Start-Process -FilePath 'php' -ArgumentList @('artisan','queue:work','--tries=3','--timeout=90') -WorkingDirectory $appRoot -WindowStyle Hidden -PassThru
    $scheduler = Start-Process -FilePath 'php' -ArgumentList @('artisan','schedule:work') -WorkingDirectory $appRoot -WindowStyle Hidden -PassThru
    try {
        Write-Host "Investment Intelligence: http://127.0.0.1:$Port"
        Write-Host "Canonical habitat: $habitatRoot"
        Write-Host "Local runtime: $runtimeRoot"
        & php artisan serve --host=127.0.0.1 --port=$Port
    } finally {
        foreach ($process in @($queue, $scheduler)) {
            if ($process -and -not $process.HasExited) { Stop-Process -Id $process.Id }
        }
    }
} finally {
    Pop-Location
}
