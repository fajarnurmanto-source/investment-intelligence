param(
    [Parameter(Mandatory)][string]$RepositoryRoot,
    [Parameter(Mandatory)][string]$RuntimeRoot,
    [string]$OutputPath = '',
    [switch]$Strict
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Read-only package inventory. Never fetch/install dependencies or read secrets.
$repo = (Resolve-Path -LiteralPath $RepositoryRoot).Path
$runtime = (Resolve-Path -LiteralPath $RuntimeRoot).Path
$checks = [System.Collections.Generic.List[object]]::new()

function Add-IIPackageCheck {
    param([string]$Name, [string]$Path, [bool]$Required = $true, [string]$Kind = 'file')
    $present = if ($Kind -eq 'directory') {
        Test-Path -LiteralPath $Path -PathType Container
    } else {
        Test-Path -LiteralPath $Path -PathType Leaf
    }
    $checks.Add([pscustomobject]@{
        name = $Name
        required = $Required
        present = [bool]$present
        kind = $Kind
    })
}

Add-IIPackageCheck 'laravel-artisan' (Join-Path $repo 'laravel_app\artisan')
Add-IIPackageCheck 'laravel-composer-lock' (Join-Path $repo 'laravel_app\composer.lock')
Add-IIPackageCheck 'laravel-public' (Join-Path $repo 'laravel_app\public') $true 'directory'
Add-IIPackageCheck 'caddyfile' (Join-Path $repo 'deploy\Caddyfile')
Add-IIPackageCheck 'start-script' (Join-Path $repo 'start-ii.ps1')
Add-IIPackageCheck 'stop-script' (Join-Path $repo 'stop-ii.ps1')
Add-IIPackageCheck 'restart-script' (Join-Path $repo 'restart-ii.ps1')
Add-IIPackageCheck 'runtime-helper' (Join-Path $repo 'tools\production\II-Production.Common.ps1')
Add-IIPackageCheck 'runtime-frankenphp' (Join-Path $runtime 'server\bin\frankenphp.exe')
Add-IIPackageCheck 'runtime-php-cli' (Join-Path $runtime 'server\bin\php.exe')
Add-IIPackageCheck 'runtime-php-extensions' (Join-Path $runtime 'server\bin\ext') $true 'directory'
Add-IIPackageCheck 'runtime-vendor-autoload' (Join-Path $runtime 'laravel\vendor\autoload.php')
Add-IIPackageCheck 'built-assets' (Join-Path $repo 'laravel_app\public\build\manifest.json')
Add-IIPackageCheck 'ca-bundle' (Join-Path $runtime 'laravel\certs\cacert.pem')

$missing = @($checks | Where-Object { $_.required -and -not $_.present } | ForEach-Object { $_.name })
$ready = $missing.Count -eq 0
$result = [ordered]@{
    schema = 'ii.prt.package-preflight.v1'
    product = 'Investment Intelligence — Personal Research Terminal'
    checked_at = [DateTimeOffset]::UtcNow.ToString('o')
    status = if ($ready) { 'INPUTS_READY_FOR_PACKAGING_REVIEW' } else { 'BLOCKED_MISSING_INPUTS' }
    packages_built = $false
    runtime_mutated = $false
    source_root = $repo
    runtime_root = $runtime
    checks = @($checks)
    missing = $missing
    risks = @(
        'An existing owner .env/APP_KEY/SQLite/credentials must never be bundled.',
        'Input readiness is not an installer build, signature, license clearance or clean-machine acceptance.',
        'A device-specific APP_KEY and local SQLite must be generated on first install.',
        'Signed dependencies, CA bundle provenance, and external component redistribution rights require review.'
    )
}
$json = $result | ConvertTo-Json -Depth 8
if ($OutputPath -ne '') {
    $folder = Split-Path -Parent $OutputPath
    if ($folder -ne '' -and -not (Test-Path -LiteralPath $folder)) {
        New-Item -ItemType Directory -Path $folder -Force | Out-Null
    }
    [System.IO.File]::WriteAllText($OutputPath, $json, [System.Text.UTF8Encoding]::new($false))
}
Write-Output $json
if ($Strict -and -not $ready) { exit 2 }
