param(
    [ValidateRange(1024, 65535)][int]$Port = 8810,
    [string]$ExpectedCommit = '',
    [switch]$SkipRecoveryPoint
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'II-Production.Common.ps1')
$context = Get-IIProductionContext -RepositoryRoot $repositoryRoot -Port $Port
Initialize-IIProductionDirectories -Context $context
Set-IIProductionEnvironment -Context $context

$dirty = @(& git -C $context.RepositoryRoot status --porcelain)
if ($dirty.Count -gt 0) { throw 'Canonical worktree must be clean before production deployment.' }
$currentCommit = (& git -C $context.RepositoryRoot rev-parse HEAD).Trim()
if ($ExpectedCommit -ne '' -and $currentCommit -ne $ExpectedCommit) { throw "Deployment commit mismatch: expected $ExpectedCommit, found $currentCommit" }

if (-not (Test-Path -LiteralPath $context.EnvironmentPath)) { Copy-Item -LiteralPath (Join-Path $context.AppRoot '.env.example') -Destination $context.EnvironmentPath }
if (-not (Test-Path -LiteralPath $context.DatabasePath)) { New-Item -ItemType File -Path $context.DatabasePath | Out-Null }
$originalAppKey = Get-IIDotEnvValue -Path $context.EnvironmentPath -Name 'APP_KEY'
if ([string]::IsNullOrWhiteSpace($originalAppKey)) { throw 'Production APP_KEY is missing; refusing deployment.' }
if (-not $SkipRecoveryPoint) {
    & (Join-Path $PSScriptRoot 'New-IIRecoveryPoint.ps1') -RepositoryRoot $context.RepositoryRoot -Label 'AUTO_PREDEPLOY'
    if ($LASTEXITCODE -ne 0) { throw 'Production recovery point failed.' }
}
& (Join-Path $PSScriptRoot 'Install-IICaBundle.ps1') -RepositoryRoot $context.RepositoryRoot
if ($LASTEXITCODE -ne 0 -or -not (Test-IICaBundle -Context $context)) { throw 'Trusted CA provisioning failed.' }

function Set-DotEnvValue {
    param([string]$Path, [string]$Name, [string]$Value)
    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) { $lines.Add($line) }
    $found = $false
    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match "^$([regex]::Escape($Name))=") { $lines[$index] = "$Name=$Value"; $found = $true; break }
    }
    if (-not $found) { $lines.Add("$Name=$Value") }
    [System.IO.File]::WriteAllLines($Path, $lines, [System.Text.UTF8Encoding]::new($false))
}

$databaseForLaravel = $context.DatabasePath.Replace('\', '/')
Set-DotEnvValue $context.EnvironmentPath 'APP_ENV' 'production'
Set-DotEnvValue $context.EnvironmentPath 'APP_DEBUG' 'false'
Set-DotEnvValue $context.EnvironmentPath 'APP_URL' $context.AppUrl
Set-DotEnvValue $context.EnvironmentPath 'LOG_LEVEL' 'warning'
Set-DotEnvValue $context.EnvironmentPath 'DB_CONNECTION' 'sqlite'
Set-DotEnvValue $context.EnvironmentPath 'DB_DATABASE' ('"' + $databaseForLaravel + '"')
Set-DotEnvValue $context.EnvironmentPath 'CACHE_STORE' 'database'
Set-DotEnvValue $context.EnvironmentPath 'SESSION_DRIVER' 'database'
Set-DotEnvValue $context.EnvironmentPath 'QUEUE_CONNECTION' 'database'
Set-DotEnvValue $context.EnvironmentPath 'II_PRODUCT_RELEASE' 'V11'
if ((Get-IIDotEnvValue -Path $context.EnvironmentPath -Name 'APP_KEY') -ne $originalAppKey) { throw 'APP_KEY changed unexpectedly; deployment aborted.' }

& (Join-Path $context.RepositoryRoot 'stop-ii.ps1') -Port $Port
Push-Location $context.AppRoot
try {
    foreach ($cacheFile in @('services.php', 'packages.php', 'config.php', 'routes.php', 'events.php')) {
        $cachePath = Join-Path $context.BootstrapCacheRoot $cacheFile
        if (Test-Path -LiteralPath $cachePath) { Remove-Item -LiteralPath $cachePath -Force }
    }
    & composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative --no-scripts
    if ($LASTEXITCODE -ne 0) { throw 'Production Composer install failed.' }
    & composer dump-autoload --no-dev --no-interaction --classmap-authoritative --no-scripts
    if ($LASTEXITCODE -ne 0) { throw 'Production autoload refresh failed.' }
    & php artisan package:discover --ansi
    if ($LASTEXITCODE -ne 0) { throw 'Laravel package discovery failed.' }
    & php artisan optimize:clear
    if ($LASTEXITCODE -ne 0) { throw 'Laravel cache reset failed.' }
    & php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Laravel migration failed.' }
    foreach ($command in @('config:cache', 'event:cache', 'route:cache', 'view:cache')) {
        & php artisan $command
        if ($LASTEXITCODE -ne 0) { throw "Laravel $command failed." }
    }
} finally { Pop-Location }

if ((Get-IIDotEnvValue -Path $context.EnvironmentPath -Name 'APP_KEY') -ne $originalAppKey) { throw 'APP_KEY verification failed after deployment.' }
if ((Get-IIDotEnvValue -Path $context.EnvironmentPath -Name 'DB_DATABASE') -ne $context.DatabasePath.Replace('\', '/')) { throw 'Canonical production database path verification failed.' }

& (Join-Path $context.RepositoryRoot 'start-ii.ps1') -Port $Port -SkipMigrate
