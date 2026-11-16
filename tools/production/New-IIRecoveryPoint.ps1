param(
    [string]$RepositoryRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)),
    [string]$Label = 'PREDEPLOY'
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'II-Production.Common.ps1')
$context = Get-IIProductionContext -RepositoryRoot $RepositoryRoot
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$safeLabel = ($Label -replace '[^A-Za-z0-9_-]', '_')
$root = Join-Path (Join-Path $context.HabitatRoot 'recovery') "PROD_${stamp}_${safeLabel}"
if (Test-Path -LiteralPath $root) { throw "Recovery point already exists: $root" }
New-Item -ItemType Directory -Path $root | Out-Null
New-Item -ItemType Directory -Path (Join-Path $root 'config') | Out-Null
& php (Join-Path $PSScriptRoot 'sqlite-backup.php') $context.DatabasePath (Join-Path $root 'ii.sqlite')
if ($LASTEXITCODE -ne 0) { throw 'Consistent SQLite backup failed.' }

foreach ($source in @($context.EnvironmentPath, (Join-Path $context.RuntimeRoot '.ii-production-research-credential.dpapi'), $context.ProcessManifest, $context.CaddyfilePath, (Join-Path $context.RepositoryRoot 'start-ii.ps1'), (Join-Path $context.RepositoryRoot 'stop-ii.ps1'), (Join-Path $context.RepositoryRoot 'restart-ii.ps1'))) {
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination (Join-Path $root 'config') -Force }
}
$storageApp = Join-Path $context.StorageRoot 'app'
if (Test-Path -LiteralPath $storageApp) { Copy-Item -LiteralPath $storageApp -Destination (Join-Path $root 'storage-app') -Recurse -Force }
if (Test-IICaBundle -Context $context) {
    New-Item -ItemType Directory -Path (Join-Path $root 'certs') -Force | Out-Null
    Copy-Item -LiteralPath $context.CaBundlePath, $context.CaMetadataPath -Destination (Join-Path $root 'certs') -Force
}
$files = Get-ChildItem -LiteralPath $root -File -Recurse
$entries = @($files | ForEach-Object { [ordered]@{
    path = $_.FullName.Substring($root.Length + 1).Replace('\', '/')
    bytes = $_.Length
    sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
} })
[ordered]@{
    schema = 'ii.production-recovery.v2'
    created_at = [DateTimeOffset]::Now.ToString('o')
    production_commit = (& git -C $context.RepositoryRoot rev-parse HEAD).Trim()
    database = 'ii.sqlite'
    files = $entries
} | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $root 'manifest.json') -Encoding utf8
$manifestHash = (Get-FileHash -LiteralPath (Join-Path $root 'manifest.json') -Algorithm SHA256).Hash.ToLowerInvariant()
Set-Content -LiteralPath (Join-Path $root 'manifest.sha256') -Value $manifestHash -Encoding ascii
if ((Get-FileHash -LiteralPath (Join-Path $root 'manifest.json') -Algorithm SHA256).Hash.ToLowerInvariant() -ne $manifestHash) { throw 'Recovery manifest verification failed.' }
Write-Host "II_RECOVERY_POINT=$root"
Write-Host "II_RECOVERY_MANIFEST_SHA256=$manifestHash"
