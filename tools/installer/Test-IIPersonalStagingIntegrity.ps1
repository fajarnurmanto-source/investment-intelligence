param([Parameter(Mandatory)][string]$PackageRoot)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = (Resolve-Path -LiteralPath $PackageRoot).Path
$manifestPath = Join-Path $root 'payload-manifest.json'
$checksumPath = Join-Path $root 'payload-manifest.sha256'
$payload = Join-Path $root 'payload'
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf) -or
    -not (Test-Path -LiteralPath $checksumPath -PathType Leaf) -or
    -not (Test-Path -LiteralPath $payload -PathType Container)) {
    throw 'II_PACKAGE_MANIFEST_OR_PAYLOAD_MISSING'
}

$expected = (Get-Content -LiteralPath $checksumPath -Raw).Trim()
$actual = (Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
if ($expected -notmatch '^[a-f0-9]{64}$' -or $expected -cne $actual) {
    throw 'II_PACKAGE_MANIFEST_INTEGRITY_FAILED'
}
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ($manifest.schema -ne 'ii.prt.staged-payload.v1' -or
    $manifest.status -ne 'STAGED_UNSIGNED_NOT_INSTALLABLE' -or
    $manifest.signed -ne $false -or $manifest.includes_secrets -ne $false) {
    throw 'II_PACKAGE_MANIFEST_POLICY_FAILED'
}
$declared = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
foreach ($item in @($manifest.files)) {
    $relative = [string]$item.path
    if ($relative -notmatch '^[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*$' -or
        $relative.Contains('..') -or
        $relative -match '(?i)(^|/)(\.env(\..*)?|storage|\.git|ii\.sqlite|database\.sqlite|secrets?|credentials?)(/|$)') {
        throw 'II_PACKAGE_MANIFEST_UNSAFE_PATH'
    }
    if (-not $declared.Add($relative)) { throw 'II_PACKAGE_DUPLICATE_MANIFEST_PATH' }
    $file = Join-Path $payload ($relative.Replace('/', '\'))
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw 'II_PACKAGE_FILE_MISSING' }
    if ((Get-Item -LiteralPath $file -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
        throw 'II_PACKAGE_REPARSE_POINT_BLOCKED'
    }
    if ((Get-Item -LiteralPath $file).Length -ne [long]$item.bytes) {
        throw 'II_PACKAGE_SIZE_MISMATCH'
    }
    if ((Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant() -cne [string]$item.sha256) {
        throw 'II_PACKAGE_HASH_MISMATCH'
    }
}
$actualPaths = @(Get-ChildItem -LiteralPath $payload -File -Recurse -Force |
    ForEach-Object { $_.FullName.Substring($payload.Length + 1).Replace('\', '/') })
foreach ($path in $actualPaths) {
    if (-not $declared.Contains($path)) { throw 'II_PACKAGE_UNDECLARED_FILE' }
}
if ([int]$manifest.file_count -ne $declared.Count -or $actualPaths.Count -ne $declared.Count) {
    throw 'II_PACKAGE_FILE_COUNT_MISMATCH'
}
Write-Output ([ordered]@{
    status = 'STAGED_PAYLOAD_INTEGRITY_VERIFIED'
    signed = $false
    installable = $false
    count = $declared.Count
    manifest_sha256 = $actual
} | ConvertTo-Json -Compress)
