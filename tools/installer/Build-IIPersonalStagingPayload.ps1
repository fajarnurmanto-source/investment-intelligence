param(
    [Parameter(Mandatory)][string]$RepositoryRoot,
    [Parameter(Mandatory)][string]$RuntimeRoot,
    [Parameter(Mandatory)][string]$OutputRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# This is a staging payload only; not a signed/distributable installer.
# Exact allowlist prevents accidental inclusion of credentials, SQLite and logs.
$repo = (Resolve-Path -LiteralPath $RepositoryRoot).Path
$runtime = (Resolve-Path -LiteralPath $RuntimeRoot).Path
$target = [IO.Path]::GetFullPath($OutputRoot)
if ($target.TrimEnd('\') -eq $repo.TrimEnd('\') -or $target.TrimEnd('\') -eq $runtime.TrimEnd('\')) {
    throw 'II_PACKAGE_OUTPUT_EQUALS_SOURCE'
}
foreach ($sourceRoot in @($repo, $runtime)) {
    if ($target.StartsWith($sourceRoot.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -or
        $sourceRoot.StartsWith($target.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw 'II_PACKAGE_OUTPUT_OVERLAPS_SOURCE'
    }
}

$preflight = & (Join-Path $PSScriptRoot 'Test-IIPersonalPackageInputs.ps1') -RepositoryRoot $repo -RuntimeRoot $runtime | ConvertFrom-Json
if ($preflight.status -ne 'INPUTS_READY_FOR_PACKAGING_REVIEW') {
    throw ('II_PACKAGE_PREFLIGHT_BLOCKED: ' + (@($preflight.missing) -join ','))
}
if (Test-Path -LiteralPath $target) {
    throw 'II_PACKAGE_OUTPUT_EXISTS: choose an empty new destination'
}

# Only application code and vetted installed runtime components are candidates.
# Public uploads, writable storage, caches, source archives and .env are excluded.
$entries = @(
    @{ root=$repo; relative='laravel_app\app'; destination='app\app'; directory=$true },
    @{ root=$repo; relative='laravel_app\bootstrap\app.php'; destination='app\bootstrap\app.php'; directory=$false },
    @{ root=$repo; relative='laravel_app\bootstrap\providers.php'; destination='app\bootstrap\providers.php'; directory=$false },
    @{ root=$repo; relative='laravel_app\config'; destination='app\config'; directory=$true },
    @{ root=$repo; relative='laravel_app\database\migrations'; destination='app\database\migrations'; directory=$true },
    @{ root=$repo; relative='laravel_app\resources'; destination='app\resources'; directory=$true },
    @{ root=$repo; relative='laravel_app\routes'; destination='app\routes'; directory=$true },
    @{ root=$repo; relative='laravel_app\artisan'; destination='app\artisan'; directory=$false },
    @{ root=$repo; relative='laravel_app\composer.json'; destination='app\composer.json'; directory=$false },
    @{ root=$repo; relative='laravel_app\composer.lock'; destination='app\composer.lock'; directory=$false },
    @{ root=$repo; relative='laravel_app\public\index.php'; destination='app\public\index.php'; directory=$false },
    @{ root=$repo; relative='laravel_app\public\build'; destination='app\public\build'; directory=$true },
    @{ root=$repo; relative='deploy\Caddyfile'; destination='deploy\Caddyfile'; directory=$false },
    @{ root=$runtime; relative='server\bin'; destination='server\bin'; directory=$true },
    @{ root=$runtime; relative='laravel\vendor'; destination='app\vendor'; directory=$true },
    @{ root=$runtime; relative='laravel\certs\cacert.pem'; destination='certs\cacert.pem'; directory=$false }
)

$forbidden = '(?i)(^|[\\/])(\.env(\..*)?|\.git|\.svn|\.ssh|storage|node_modules|tests|database\.sqlite|ii\.sqlite|\.htaccess|\.user\.ini|id_rsa|id_ed25519|credentials?(\..*)?|secrets?(\..*)?|\.dpapi)([\\/]|$)|(?i)\.(sqlite|sqlite3|db|key|pem\.key|pfx|p12|log)$'
$files = [System.Collections.Generic.List[object]]::new()
foreach ($item in $entries) {
    $src = Join-Path $item.root $item.relative
    if (-not (Test-Path -LiteralPath $src)) { throw "II_PACKAGE_INPUT_MISSING: $($item.relative)" }
    if ((Get-Item -LiteralPath $src -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
        throw 'II_PACKAGE_REPARSE_POINT_BLOCKED'
    }
    if ($item.directory) {
        $children = @(Get-ChildItem -LiteralPath $src -File -Recurse -Force)
        if ($children.Count -eq 0) { throw "II_PACKAGE_EMPTY_DIRECTORY: $($item.relative)" }
    } else {
        $children = @(Get-Item -LiteralPath $src -Force)
    }
    foreach ($file in $children) {
        if ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw "II_PACKAGE_REPARSE_POINT_BLOCKED: $($file.Name)"
        }
        $suffix = if ($item.directory) { $file.FullName.Substring($src.Length).TrimStart('\') } else { '' }
        $dest = if ($suffix -ne '') { Join-Path $item.destination $suffix } else { $item.destination }
        if ($dest -match $forbidden -or $file.FullName -match $forbidden) {
            throw "II_PACKAGE_FORBIDDEN_INPUT: $dest"
        }
        $files.Add([pscustomobject]@{ source=$file.FullName; relative=$dest.Replace('\','/') })
    }
}
$duplicates = @($files | Group-Object relative | Where-Object Count -gt 1)
if ($duplicates.Count -gt 0) { throw 'II_PACKAGE_DUPLICATE_DESTINATION' }

New-Item -ItemType Directory -Path $target -Force | Out-Null
$stage = Join-Path $target 'payload'
$records = [System.Collections.Generic.List[object]]::new()
try {
    foreach ($file in @($files | Sort-Object relative)) {
        $to = Join-Path $stage ($file.relative.Replace('/', '\'))
        $folder = Split-Path -Parent $to
        if (-not (Test-Path -LiteralPath $folder)) { New-Item -ItemType Directory -Path $folder -Force | Out-Null }
        Copy-Item -LiteralPath $file.source -Destination $to -ErrorAction Stop
        $before = (Get-FileHash -LiteralPath $file.source -Algorithm SHA256).Hash.ToLowerInvariant()
        $after = (Get-FileHash -LiteralPath $to -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($before -ne $after) { throw "II_PACKAGE_COPY_HASH_MISMATCH: $($file.relative)" }
        $records.Add([ordered]@{ path=$file.relative; bytes=(Get-Item -LiteralPath $to).Length; sha256=$after })
    }
    $manifest = [ordered]@{
        schema = 'ii.prt.staged-payload.v1'
        status = 'STAGED_UNSIGNED_NOT_INSTALLABLE'
        includes_secrets = $false
        device_initialized = $false
        redistributable_licenses_verified = $false
        signed = $false
        file_count = $records.Count
        files = @($records)
    }
    $json = $manifest | ConvertTo-Json -Depth 7
    $manifestPath = Join-Path $target 'payload-manifest.json'
    [IO.File]::WriteAllText($manifestPath,$json,[Text.UTF8Encoding]::new($false))
    $sha = (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    [IO.File]::WriteAllText((Join-Path $target 'payload-manifest.sha256'),$sha,[Text.Encoding]::ASCII)
    Write-Output ([pscustomobject]@{ status=$manifest.status; file_count=$records.Count; manifest_sha256=$sha } | ConvertTo-Json -Compress)
} catch {
    Remove-Item -LiteralPath $target -Recurse -Force -ErrorAction SilentlyContinue
    throw
}
