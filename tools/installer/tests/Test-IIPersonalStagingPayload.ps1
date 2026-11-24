Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$build = Join-Path (Split-Path -Parent $PSScriptRoot) 'Build-IIPersonalStagingPayload.ps1'
$root = Join-Path ([IO.Path]::GetTempPath()) ('ii-prt-staging-'+[Guid]::NewGuid().ToString('N'))
$repo = Join-Path $root 'repo'
$runtime = Join-Path $root 'runtime'
$output = Join-Path $root 'output'
function New-FixtureFile([string]$base, [string]$relative, [string]$text = 'synthetic') {
    $path = Join-Path $base $relative
    New-Item -ItemType Directory -Path (Split-Path -Parent $path) -Force | Out-Null
    [IO.File]::WriteAllText($path, $text)
}
try {
    New-Item -ItemType Directory -Path $repo,$runtime -Force | Out-Null
    $repoFiles = @(
        'laravel_app\app\Example.php',
        'laravel_app\bootstrap\app.php',
        'laravel_app\bootstrap\providers.php',
        'laravel_app\config\app.php',
        'laravel_app\database\migrations\2026_create_table.php',
        'laravel_app\resources\views\welcome.blade.php',
        'laravel_app\routes\web.php',
        'laravel_app\artisan',
        'laravel_app\composer.json',
        'laravel_app\composer.lock',
        'laravel_app\public\index.php',
        'laravel_app\public\build\manifest.json',
        'deploy\Caddyfile',
        'start-ii.ps1',
        'stop-ii.ps1',
        'restart-ii.ps1',
        'tools\production\II-Production.Common.ps1'
    )
    foreach ($f in $repoFiles) { New-FixtureFile $repo $f }
    $runtimeFiles = @(
        'server\bin\frankenphp.exe',
        'server\bin\php.exe',
        'server\bin\ext\php_sqlite3.dll',
        'laravel\vendor\autoload.php',
        'laravel\certs\cacert.pem'
    )
    foreach ($f in $runtimeFiles) { New-FixtureFile $runtime $f }

    # A real personal runtime may contain secrets. They must never appear.
    New-FixtureFile $runtime 'laravel\.env' 'APP_KEY=secret-should-never-ship'
    New-FixtureFile $runtime 'laravel\database\ii.sqlite' 'private database'
    New-FixtureFile $runtime 'laravel\storage\app\private\evidence.raw' 'private evidence'
    New-FixtureFile $repo 'laravel_app\public\uploads\personal.jpg' 'private upload'
    $receipt = (& $build -RepositoryRoot $repo -RuntimeRoot $runtime -OutputRoot $output | ConvertFrom-Json)
    if ($receipt.status -ne 'STAGED_UNSIGNED_NOT_INSTALLABLE') { throw 'STAGE_STATUS_INVALID' }
    if ($receipt.file_count -lt 12) { throw 'STAGE_FILE_COUNT_INVALID' }
    $manifestFile = Join-Path $output 'payload-manifest.json'
    $manifest = Get-Content -LiteralPath $manifestFile -Raw | ConvertFrom-Json
    if ($manifest.file_count -ne $receipt.file_count) { throw 'STAGE_MANIFEST_COUNT_INVALID' }
    if ($manifest.signed -ne $false -or $manifest.device_initialized -ne $false) { throw 'STAGE_FALSE_READY' }
    foreach ($entry in $manifest.files) {
        $path = Join-Path (Join-Path $output 'payload') ($entry.path.Replace('/', '\'))
        if (-not (Test-Path -LiteralPath $path)) { throw 'STAGE_MISSING_PAYLOAD_FILE' }
        if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $entry.sha256) {
            throw 'STAGE_HASH_MISMATCH'
        }
    }
    $expectedHash = (Get-FileHash -LiteralPath $manifestFile -Algorithm SHA256).Hash.ToLowerInvariant()
    if ((Get-Content -LiteralPath (Join-Path $output 'payload-manifest.sha256') -Raw).Trim() -ne $expectedHash) {
        throw 'STAGE_MANIFEST_SHA_MISMATCH'
    }
    if (Test-Path -LiteralPath (Join-Path $output 'payload\app\.env')) { throw 'STAGE_LEAK_ENV' }
    if (Test-Path -LiteralPath (Join-Path $output 'payload\app\database\ii.sqlite')) { throw 'STAGE_LEAK_DB' }
    if (Test-Path -LiteralPath (Join-Path $output 'payload\app\public\uploads')) { throw 'STAGE_LEAK_UPLOADS' }
    if (Test-Path -LiteralPath (Join-Path $output 'payload\app\storage')) { throw 'STAGE_LEAK_STORAGE' }

    # Independent manifest verification must reject both tampering and extra files.
    $verify = Join-Path (Split-Path -Parent $PSScriptRoot) 'Test-IIPersonalStagingIntegrity.ps1'
    $checked = (& $verify -PackageRoot $output | ConvertFrom-Json)
    if ($checked.status -ne 'STAGED_PAYLOAD_INTEGRITY_VERIFIED' -or $checked.installable -ne $false) {
        throw 'STAGE_VERIFIER_EXPECTED_PASS'
    }
    $chosen = Join-Path $output 'payload\app\artisan'
    [IO.File]::AppendAllText($chosen, 'TAMPER')
    $tamperRejected = $false
    try { & $verify -PackageRoot $output | Out-Null } catch { $tamperRejected = $true }
    if (-not $tamperRejected) { throw 'STAGE_TAMPER_NOT_REJECTED' }
    [IO.File]::WriteAllText($chosen, 'synthetic')
    $extra = Join-Path $output 'payload\app\undeclared.txt'
    [IO.File]::WriteAllText($extra, 'extra')
    $extraRejected = $false
    try { & $verify -PackageRoot $output | Out-Null } catch { $extraRejected = $true }
    if (-not $extraRejected) { throw 'STAGE_UNDECLARED_FILE_NOT_REJECTED' }
    Remove-Item -LiteralPath $extra -Force

    # Refuse accidental overwrite and do not alter previous staged artifact.
    $refused = $false
    try {
        & $build -RepositoryRoot $repo -RuntimeRoot $runtime -OutputRoot $output | Out-Null
    } catch { $refused = $true }
    if (-not $refused) { throw 'STAGE_OUTPUT_OVERWRITE_NOT_BLOCKED' }
    if ((Get-FileHash -LiteralPath $manifestFile -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedHash) {
        throw 'STAGE_OUTPUT_MUTATED'
    }

    # Fail before creating an output folder if a sensitive file appears in an allowlisted input.
    New-FixtureFile $repo 'laravel_app\resources\.env' 'DO_NOT_PACKAGE=true'
    $blocked = $false
    $blockedOutput = Join-Path $root 'blocked'
    try {
        & $build -RepositoryRoot $repo -RuntimeRoot $runtime -OutputRoot $blockedOutput | Out-Null
    } catch { $blocked = $true }
    if (-not $blocked -or (Test-Path -LiteralPath $blockedOutput)) { throw 'STAGE_SECRETS_FAIL_CLOSED_INVALID' }
    Write-Host 'II_PRT_M08_PAYLOAD_BUILDER_TEST=PASS'
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
