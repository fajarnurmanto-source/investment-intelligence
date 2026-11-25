param(
    [ValidateRange(1024, 65535)][int]$Port = 8810,
    [switch]$SkipMigrate
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'tools\production\II-Production.Common.ps1')
$context = Get-IIProductionContext -RepositoryRoot $PSScriptRoot -Port $Port
$mutex = Enter-IIRuntimeLock -Context $context
try {
Set-IIProductionEnvironment -Context $context
Initialize-IIProductionDirectories -Context $context

if (-not (Test-Path -LiteralPath $context.EnvironmentPath)) {
    throw "Production environment is missing. Run tools\production\Deploy-IIProduction.ps1 first."
}
if (-not (Test-Path -LiteralPath $context.FrankenPhpPath)) { throw "FrankenPHP is missing: $($context.FrankenPhpPath)" }
if (-not (Test-Path -LiteralPath (Join-Path $context.VendorRoot 'autoload.php'))) { throw "Composer dependencies are missing: $($context.VendorRoot)" }

# Persistent runtime trust check. Offline features remain usable if SEC
# trust is missing, but we never silently report SEC as healthy.
$SecCaBundle = $context.CaBundlePath
if (Test-IICaBundle -Context $context) {
    Write-Host 'II_SEC_CA_BUNDLE=READY'
} else {
    Write-Warning 'II_SEC_CA_BUNDLE=MISSING_OR_INVALID; SEC official API disabled until runtime CA repair. Run: php artisan ii:doctor'
}

$status = Get-IIRuntimeStatus -Context $context
if ($status.state -eq 'HEALTHY') {
    Write-Host "Investment Intelligence already running at $($context.AppUrl)"
    return
}
if ($status.state -eq 'UNMANAGED_LISTENER') { throw 'II_START_UNMANAGED_LISTENER' }

$existing = Read-IIProcessManifest -Context $context
if ($existing) {
    $alive = @($existing.processes | Where-Object { $null -ne (Get-IIManagedProcess -Entry $_) })
    if ($alive.Count -gt 0) { throw 'Investment Intelligence is already running. Use restart-ii.ps1.' }
    Remove-Item -LiteralPath $context.ProcessManifest -Force -ErrorAction SilentlyContinue
}

if (Test-IIListeningPort -Port $Port) { throw "Port $Port is already in use outside the managed II process manifest." }

Push-Location $context.AppRoot
try {
    if (-not $SkipMigrate) {
        & php artisan migrate --force
        if ($LASTEXITCODE -ne 0) { throw 'Laravel migration failed.' }
    }
    $serverEnvironment = @{
        PHPRC = $context.PhpIniRoot
        II_PHP_EXTENSION_DIR = $context.PhpExtensionRoot
    }
    $server = Start-IIManagedProcess -Name 'web' -FilePath $context.FrankenPhpPath -ArgumentList @('run', '--config', '..\deploy\Caddyfile', '--adapter', 'caddyfile') -WorkingDirectory $context.AppRoot -LogRoot $context.LogRoot -Environment $serverEnvironment
    $manifest = [ordered]@{
        started_at = [DateTimeOffset]::Now.ToString('o')
        url = $context.AppUrl
        commit = (& git -C $context.RepositoryRoot rev-parse HEAD).Trim()
        processes = @($server)
    }
    Write-IIProcessManifest -Context $context -Manifest $manifest
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Milliseconds 500
        try {
            if ((Invoke-WebRequest -Uri "$($context.AppUrl)/up" -UseBasicParsing -TimeoutSec 2).StatusCode -eq 200) { $ready = $true; break }
        } catch { }
        if (-not (Test-IIProcess -ProcessId $server.id)) { break }
    }
    if (-not $ready) {
        & (Join-Path $context.RepositoryRoot 'stop-ii.ps1') -Port $Port
        throw "Investment Intelligence did not become healthy. Review $($context.LogRoot)."
    }
    $queue = Start-IIManagedProcess -Name 'queue' -FilePath 'php' -ArgumentList @('artisan', 'queue:work', '--sleep=3', '--tries=3', '--timeout=90') -WorkingDirectory $context.AppRoot -LogRoot $context.LogRoot
    # Persist ownership of every newly launched process immediately. If the
    # scheduler fails to start, stop-ii.ps1 must also be able to stop queue.
    $manifest.processes = @($server, $queue)
    Write-IIProcessManifest -Context $context -Manifest $manifest
    $scheduler = Start-IIManagedProcess -Name 'scheduler' -FilePath 'php' -ArgumentList @('artisan', 'schedule:work') -WorkingDirectory $context.AppRoot -LogRoot $context.LogRoot
    $manifest.processes = @($server, $queue, $scheduler)
    Write-IIProcessManifest -Context $context -Manifest $manifest
    Start-Sleep -Milliseconds 750
    if ((Get-IIRuntimeStatus -Context $context).state -ne 'HEALTHY') {
        throw 'II_START_MANAGED_PROCESS_READINESS_FAILED'
    }
    Write-Host "Investment Intelligence started at $($context.AppUrl)"
    Write-Host "Managed processes: web=$($server.id), queue=$($queue.id), scheduler=$($scheduler.id)"
} catch {
    if (Test-Path -LiteralPath $context.ProcessManifest) { & (Join-Path $context.RepositoryRoot 'stop-ii.ps1') -Port $Port }
    throw
} finally {
    Pop-Location
}

} finally { Exit-IIRuntimeLock -Mutex $mutex }
