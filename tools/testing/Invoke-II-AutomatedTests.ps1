#Requires -Version 7.0
<#
.SYNOPSIS
  Run II's existing Tinker and PHPUnit automation in a completely separate
  Laravel test habitat. NEVER installs dev packages into production.
.EXAMPLE
  pwsh -NoProfile -ExecutionPolicy Bypass -File ".\tools\testing\Invoke-II-AutomatedTests.ps1"
#>
[CmdletBinding()]
param(
    [switch]$SkipComposerInstall
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repository = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..')).Path
$appRoot = Join-Path $repository 'laravel_app'
$habitat = Split-Path -Parent $repository
$productionRoot = Join-Path $habitat 'runtime\laravel'
if (-not (Test-Path -LiteralPath (Join-Path $appRoot 'composer.lock'))) {
    throw 'II_TEST_PRECHECK=FAIL; composer.lock is missing'
}
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { throw 'II_TEST_PRECHECK=FAIL; PHP CLI not found' }
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { throw 'II_TEST_PRECHECK=FAIL; Composer not found' }

$manifest = Get-Content -LiteralPath (Join-Path $appRoot 'composer.json') -Raw | ConvertFrom-Json
if (-not $manifest.require.'laravel/tinker') {
    throw 'II_TEST_TINKER=NOT_DECLARED; do not alter production dependencies automatically'
}
$lock = Get-Content -LiteralPath (Join-Path $appRoot 'composer.lock') -Raw | ConvertFrom-Json
$tinker = @($lock.packages | Where-Object { $_.name -eq 'laravel/tinker' })
if ($tinker.Count -ne 1) { throw 'II_TEST_TINKER=NOT_LOCKED; run composer install on a development checkout first' }

$testBase = Join-Path $env:LOCALAPPDATA 'InvestmentIntelligence\Testing'
$testRoot = [System.IO.Path]::GetFullPath($testBase).TrimEnd('\')
$productionFull = [System.IO.Path]::GetFullPath($productionRoot).TrimEnd('\')
if ($testRoot.Equals($productionFull, [StringComparison]::OrdinalIgnoreCase) -or
    $testRoot.StartsWith($productionFull + '\', [StringComparison]::OrdinalIgnoreCase) -or
    $productionFull.StartsWith($testRoot + '\', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'II_TEST_ISOLATION=FAIL; test runtime overlaps production'
}

$vendor = Join-Path $testRoot 'vendor'
$cache = Join-Path $testRoot 'bootstrap-cache'
@($testRoot, $vendor, $cache,
  (Join-Path $testRoot 'storage\logs'),
  (Join-Path $testRoot 'storage\framework\views'),
  (Join-Path $testRoot 'storage\framework\sessions'),
  (Join-Path $testRoot 'storage\framework\cache\data')) | ForEach-Object {
    New-Item -ItemType Directory -Path $_ -Force | Out-Null
}

# Restrict both PHP processes and Composer to isolated dev dependencies.
# Never read or write runtime/laravel/.env or runtime/laravel/database/ii.sqlite.
$env:II_RUNTIME_ROOT = $testRoot
$env:II_VENDOR_PATH = $vendor
$env:COMPOSER_VENDOR_DIR = $vendor
$env:APP_BASE_PATH = $appRoot
$env:APP_SERVICES_CACHE = Join-Path $cache 'services.php'
$env:APP_PACKAGES_CACHE = Join-Path $cache 'packages.php'
$env:APP_CONFIG_CACHE = Join-Path $cache 'config.php'
$env:APP_ROUTES_CACHE = Join-Path $cache 'routes.php'
$env:APP_EVENTS_CACHE = Join-Path $cache 'events.php'
$env:APP_ENV = 'testing'
$env:APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
$env:APP_DEBUG = 'false'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:DB_URL = ''
$env:SESSION_DRIVER = 'array'
$env:CACHE_STORE = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'
$env:LOG_CHANNEL = 'stderr'
$env:II_PHP_EXTENSION_DIR = ''

$envFile = Join-Path $testRoot '.env'
@(
    'APP_NAME="Investment Intelligence (Automated Tests)"'
    'APP_ENV=testing'
    ('APP_KEY=' + $env:APP_KEY)
    'APP_DEBUG=false'
    'DB_CONNECTION=sqlite'
    'DB_DATABASE=:memory:'
    'SESSION_DRIVER=array'
    'CACHE_STORE=array'
    'QUEUE_CONNECTION=sync'
    'MAIL_MAILER=array'
    'LOG_CHANNEL=stderr'
) | Set-Content -LiteralPath $envFile -Encoding utf8NoBOM

# Reject stale test caches from prior changes; never touch production caches.
@('services.php', 'packages.php', 'config.php', 'routes.php', 'events.php') | ForEach-Object {
    $item = Join-Path $cache $_
    if (Test-Path -LiteralPath $item) { Remove-Item -LiteralPath $item -Force }
}

Write-Host "II_TEST_TINKER_LOCKED_VERSION=$($tinker[0].version)"
Write-Host 'II_TEST_ISOLATED_RUNTIME=PASS'
Write-Host 'II_TEST_DATABASE=SQLITE_MEMORY'
Write-Host 'II_TEST_PRODUCTION_RUNTIME_UNTOUCHED=YES'

Push-Location $appRoot
try {
    if (-not $SkipComposerInstall) {
        & composer install --no-interaction --prefer-dist --no-progress --no-scripts
        if ($LASTEXITCODE -ne 0) { throw 'II_TEST_COMPOSER=FAIL' }
    }
    if (-not (Test-Path -LiteralPath (Join-Path $vendor 'autoload.php'))) {
        throw 'II_TEST_VENDOR=FAIL; run without -SkipComposerInstall'
    }

    & php artisan package:discover --ansi
    if ($LASTEXITCODE -ne 0) { throw 'II_TEST_DISCOVERY=FAIL' }

    # Tinker is a smoke probe, not the automated assertion runner.
    # Execute only read-only code inside the isolated testing environment.
    $smoke = "--execute=echo (app()->environment('testing') && config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:' ? 'II_TINKER_SMOKE=PASS' : 'II_TINKER_SMOKE=FAIL');"
    $output = (& php artisan tinker $smoke 2>&1 | Out-String)
    if ($LASTEXITCODE -ne 0 -or $output -notmatch 'II_TINKER_SMOKE=PASS') {
        Write-Host $output
        throw 'II_TINKER_SMOKE=FAIL'
    }
    Write-Host 'II_TINKER_SMOKE=PASS'

    if ($env:DB_DATABASE -ne ':memory:' -or $env:II_RUNTIME_ROOT -ne $testRoot) {
        throw 'II_TEST_DATABASE_GUARD=FAIL'
    }
    & php artisan test --stop-on-failure
    if ($LASTEXITCODE -ne 0) { throw 'II_AUTOMATED_REGRESSION=FAIL' }
    Write-Host 'II_AUTOMATED_REGRESSION=PASS'
    Write-Host 'II_TEST_PRODUCTION_DATABASE_UNTOUCHED_BY_RUNNER=YES'
    Write-Host 'II_TEST_COMPLETE=PASS'
} finally {
    Pop-Location
}
