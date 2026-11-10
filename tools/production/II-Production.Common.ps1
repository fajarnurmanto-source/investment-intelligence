Set-StrictMode -Version Latest

function Get-IIProductionContext {
    param([Parameter(Mandatory)][string]$RepositoryRoot, [int]$Port = 8810)
    $repository = (Resolve-Path -LiteralPath $RepositoryRoot).Path
    $habitat = Split-Path -Parent $repository
    $runtime = Join-Path $habitat 'runtime\laravel'
    $server = Join-Path $habitat 'runtime\server'
    [pscustomobject]@{
        RepositoryRoot = $repository
        HabitatRoot = $habitat
        AppRoot = Join-Path $repository 'laravel_app'
        RuntimeRoot = $runtime
        StorageRoot = Join-Path $runtime 'storage'
        BootstrapCacheRoot = Join-Path $runtime 'bootstrap-cache'
        DatabasePath = Join-Path $runtime 'database\ii.sqlite'
        EnvironmentPath = Join-Path $runtime '.env'
        VendorRoot = Join-Path $runtime 'vendor'
        FrankenPhpPath = Join-Path $server 'bin\frankenphp.exe'
        PhpExtensionRoot = Join-Path $server 'bin\ext'
        PhpIniRoot = Join-Path $repository 'deploy'
        ProcessManifest = Join-Path $server 'processes.json'
        LogRoot = Join-Path $runtime 'storage\logs\services'
        CaddyfilePath = Join-Path $repository 'deploy\Caddyfile'
        AppUrl = "http://127.0.0.1:$Port"
        BindAddress = "127.0.0.1:$Port"
    }
}

function Initialize-IIProductionDirectories {
    param([Parameter(Mandatory)]$Context)
    @(
        $Context.RuntimeRoot, $Context.BootstrapCacheRoot,
        (Split-Path -Parent $Context.DatabasePath), $Context.LogRoot,
        (Join-Path $Context.StorageRoot 'app\private'),
        (Join-Path $Context.StorageRoot 'app\public'),
        (Join-Path $Context.StorageRoot 'framework\cache\data'),
        (Join-Path $Context.StorageRoot 'framework\sessions'),
        (Join-Path $Context.StorageRoot 'framework\views')
    ) | ForEach-Object {
        if (-not (Test-Path -LiteralPath $_)) { New-Item -ItemType Directory -Path $_ -Force | Out-Null }
    }
}

function Set-IIProductionEnvironment {
    param([Parameter(Mandatory)]$Context)
    $env:II_RUNTIME_ROOT = $Context.RuntimeRoot
    $env:II_VENDOR_PATH = $Context.VendorRoot
    $env:COMPOSER_VENDOR_DIR = $Context.VendorRoot
    $env:APP_BASE_PATH = $Context.AppRoot
    $env:APP_SERVICES_CACHE = Join-Path $Context.BootstrapCacheRoot 'services.php'
    $env:APP_PACKAGES_CACHE = Join-Path $Context.BootstrapCacheRoot 'packages.php'
    $env:APP_CONFIG_CACHE = Join-Path $Context.BootstrapCacheRoot 'config.php'
    $env:APP_ROUTES_CACHE = Join-Path $Context.BootstrapCacheRoot 'routes.php'
    $env:APP_EVENTS_CACHE = Join-Path $Context.BootstrapCacheRoot 'events.php'
    $env:II_BIND_ADDRESS = $Context.BindAddress
    $env:II_PUBLIC_ROOT = (Join-Path $Context.AppRoot 'public').Replace('\', '/')
}

function Test-IIProcess {
    param([Parameter(Mandatory)][int]$ProcessId)
    return $null -ne (Get-Process -Id $ProcessId -ErrorAction SilentlyContinue)
}

function Test-IIListeningPort {
    param([Parameter(Mandatory)][int]$Port)
    if (Get-Command Get-NetTCPConnection -ErrorAction SilentlyContinue) {
        return @(
            Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
        ).Count -gt 0
    }
    $client = [Net.Sockets.TcpClient]::new()
    try {
        $task = $client.ConnectAsync('127.0.0.1', $Port)
        return $task.Wait(500) -and $client.Connected
    } catch { return $false } finally { $client.Dispose() }
}

function Get-IIManagedProcess {
    param([Parameter(Mandatory)]$Entry)
    $process = Get-Process -Id ([int]$Entry.id) -ErrorAction SilentlyContinue
    if (-not $process) { return $null }
    $expectedName = if ($Entry.name -eq 'web') { 'frankenphp' } else { 'php' }
    if ($process.ProcessName -ne $expectedName) { return $null }
    try {
        if ($Entry.started_at -is [DateTime]) {
            $recorded = ([DateTime]$Entry.started_at).ToLocalTime()
        } elseif ($Entry.started_at -is [DateTimeOffset]) {
            $recorded = ([DateTimeOffset]$Entry.started_at).LocalDateTime
        } else {
            $recorded = [DateTimeOffset]::Parse(
                [string]$Entry.started_at,
                [Globalization.CultureInfo]::InvariantCulture,
                [Globalization.DateTimeStyles]::RoundtripKind
            ).LocalDateTime
        }
        if ([Math]::Abs(($process.StartTime - $recorded).TotalSeconds) -gt 30) { return $null }
    } catch { return $null }
    return $process
}

function Read-IIProcessManifest {
    param([Parameter(Mandatory)]$Context)
    if (-not (Test-Path -LiteralPath $Context.ProcessManifest)) { return $null }
    return Get-Content -LiteralPath $Context.ProcessManifest -Raw | ConvertFrom-Json
}

function Write-IIProcessManifest {
    param([Parameter(Mandatory)]$Context, [Parameter(Mandatory)]$Manifest)
    $Manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $Context.ProcessManifest -Encoding utf8
}

function Start-IIManagedProcess {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$FilePath,
        [Parameter(Mandatory)][string[]]$ArgumentList,
        [Parameter(Mandatory)][string]$WorkingDirectory,
        [Parameter(Mandatory)][string]$LogRoot,
        [hashtable]$Environment = @{}
    )
    $stdout = Join-Path $LogRoot "$Name.stdout.log"
    $stderr = Join-Path $LogRoot "$Name.stderr.log"
    $process = Start-Process -FilePath $FilePath -ArgumentList $ArgumentList -WorkingDirectory $WorkingDirectory -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -Environment $Environment -PassThru
    [pscustomobject]@{ name = $Name; id = $process.Id; started_at = [DateTimeOffset]::Now.ToString('o') }
}
