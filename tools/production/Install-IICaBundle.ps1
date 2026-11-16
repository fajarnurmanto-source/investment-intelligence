param(
    [string]$RepositoryRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)),
    [string]$RuntimeRoot = '',
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'II-Production.Common.ps1')
$context = Get-IIProductionContext -RepositoryRoot $RepositoryRoot
if (-not [string]::IsNullOrWhiteSpace($RuntimeRoot)) {
    $resolvedRuntime = [System.IO.Path]::GetFullPath($RuntimeRoot)
    $context.CaBundlePath = Join-Path $resolvedRuntime 'certs\cacert.pem'
    $context.CaMetadataPath = Join-Path $resolvedRuntime 'certs\cacert.metadata.json'
}
$certRoot = Split-Path -Parent $context.CaBundlePath
if (-not (Test-Path -LiteralPath $certRoot)) { New-Item -ItemType Directory -Path $certRoot -Force | Out-Null }
if (-not $Force -and (Test-IICaBundle -Context $context)) { Write-Host 'II_CA_BUNDLE=READY'; exit 0 }

$pemUrl = 'https://curl.se/ca/cacert.pem'
$hashUrl = 'https://curl.se/ca/cacert.pem.sha256'
$pemTemp = Join-Path $certRoot ('cacert.' + [guid]::NewGuid().ToString('N') + '.tmp')
$hashTemp = Join-Path $certRoot ('cacert.' + [guid]::NewGuid().ToString('N') + '.sha256.tmp')
try {
    Invoke-WebRequest -Uri $pemUrl -OutFile $pemTemp -UseBasicParsing -TimeoutSec 60
    Invoke-WebRequest -Uri $hashUrl -OutFile $hashTemp -UseBasicParsing -TimeoutSec 30
    $expectedText = (Get-Content -LiteralPath $hashTemp -Raw).Trim()
    $match = [regex]::Match($expectedText, '(?i)\b[0-9a-f]{64}\b')
    if (-not $match.Success) { throw 'Official curl CA checksum response is invalid.' }
    $expected = $match.Value.ToLowerInvariant()
    $actual = (Get-FileHash -LiteralPath $pemTemp -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $expected) { throw 'Downloaded CA bundle checksum does not match curl.se.' }
    $contents = Get-Content -LiteralPath $pemTemp -Raw
    $blocks = ([regex]::Matches($contents, '-----BEGIN CERTIFICATE-----')).Count
    if ((Get-Item -LiteralPath $pemTemp).Length -le 100000 -or $blocks -lt 70) { throw 'Downloaded CA bundle is incomplete.' }
    Move-Item -LiteralPath $pemTemp -Destination $context.CaBundlePath -Force
    [ordered]@{
        schema = 'ii.runtime-ca.v1'
        source = $pemUrl
        checksum_source = $hashUrl
        installed_at = [DateTimeOffset]::Now.ToString('o')
        sha256 = $actual
        certificate_count = $blocks
    } | ConvertTo-Json | Set-Content -LiteralPath $context.CaMetadataPath -Encoding utf8
    if (-not (Test-IICaBundle -Context $context)) { throw 'Installed CA bundle failed local verification.' }
    Write-Host "II_CA_BUNDLE=INSTALLED;SHA256=$actual;CERTIFICATES=$blocks"
} finally {
    Remove-Item -LiteralPath $pemTemp -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $hashTemp -Force -ErrorAction SilentlyContinue
}
