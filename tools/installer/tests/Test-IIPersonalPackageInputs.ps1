Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script = Join-Path (Split-Path -Parent $PSScriptRoot) 'Test-IIPersonalPackageInputs.ps1'
if (-not (Test-Path -LiteralPath $script)) { throw 'PREFLIGHT_SCRIPT_MISSING' }

$root = Join-Path ([IO.Path]::GetTempPath()) ('ii-prt-m08-'+[Guid]::NewGuid().ToString('N'))
$repo = Join-Path $root 'repository'
$runtime = Join-Path $root 'runtime'
try {
    New-Item -ItemType Directory -Path $repo, $runtime -Force | Out-Null
    $required = @(
        'laravel_app\artisan',
        'laravel_app\composer.lock',
        'deploy\Caddyfile',
        'start-ii.ps1',
        'stop-ii.ps1',
        'restart-ii.ps1',
        'tools\production\II-Production.Common.ps1',
        'laravel_app\public\build\manifest.json'
    )
    foreach ($path in $required) {
        $target = Join-Path $repo $path
        New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
        [IO.File]::WriteAllText($target, 'synthetic preflight fixture')
    }
    $runtimeFiles = @(
        'server\bin\frankenphp.exe',
        'server\bin\php.exe',
        'laravel\vendor\autoload.php',
        'laravel\certs\cacert.pem'
    )
    foreach ($path in $runtimeFiles) {
        $target = Join-Path $runtime $path
        New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
        [IO.File]::WriteAllText($target, 'synthetic preflight fixture')
    }
    New-Item -ItemType Directory -Path (Join-Path $runtime 'server\bin\ext') -Force | Out-Null
    $good = (& $script -RepositoryRoot $repo -RuntimeRoot $runtime | ConvertFrom-Json)
    if ($good.status -ne 'INPUTS_READY_FOR_PACKAGING_REVIEW') { throw 'PREFLIGHT_EXPECTED_READY' }
    if ($good.packages_built -ne $false -or $good.runtime_mutated -ne $false) { throw 'PREFLIGHT_FALSE_COMPLETION_CLAIM' }
    if ($good.checks.Count -lt 12) { throw 'PREFLIGHT_CHECKS_MISSING' }

    Remove-Item -LiteralPath (Join-Path $runtime 'server\bin\php.exe') -Force
    $bad = (& $script -RepositoryRoot $repo -RuntimeRoot $runtime | ConvertFrom-Json)
    if ($bad.status -ne 'BLOCKED_MISSING_INPUTS') { throw 'PREFLIGHT_EXPECTED_BLOCKED' }
    if ('runtime-php-cli' -notin @($bad.missing)) { throw 'PREFLIGHT_PHP_GATE_MISSING' }
    if ($bad.checks.Count -ne $good.checks.Count) { throw 'PREFLIGHT_UNSTABLE_INVENTORY' }
    Write-Host 'II_PRT_M08_PREFLIGHT_TEST=PASS'
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
