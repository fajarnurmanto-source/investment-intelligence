# Runs under Windows PowerShell 5.1 AND PowerShell 7.
# A disposable cmd.exe process proves environment propagation and restoration.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..\..')).Path
. (Join-Path $repo 'tools\production\II-Production.Common.ps1')
$root = Join-Path ([IO.Path]::GetTempPath()) ('ii-ps-compat-'+[Guid]::NewGuid().ToString('N'))
$old = [Environment]::GetEnvironmentVariable('II_TEST_CHILD_MARKER','Process')
try {
    New-Item -ItemType Directory -Path $root -Force | Out-Null
    [Environment]::SetEnvironmentVariable('II_TEST_CHILD_MARKER','parent-original','Process')
    $options = @{
        Name = 'compat'
        FilePath = $env:ComSpec
        ArgumentList = @('/d','/c','echo %II_TEST_CHILD_MARKER%')
        WorkingDirectory = $root
        LogRoot = $root
        Environment = @{ II_TEST_CHILD_MARKER = 'child-only' }
    }
    $entry = Start-IIManagedProcess @options
    if ($entry.id -le 0 -or $entry.name -ne 'compat') {
        throw 'II_PS_COMPAT_PROCESS_MANIFEST_INVALID'
    }
    if ([Environment]::GetEnvironmentVariable('II_TEST_CHILD_MARKER','Process') -ne 'parent-original') {
        throw 'II_PS_COMPAT_PARENT_ENV_LEAK'
    }
    $stdout = Join-Path $root 'compat.stdout.log'
    $observed = ''
    for ($i = 0; $i -lt 50; $i++) {
        Start-Sleep -Milliseconds 100
        if (Test-Path -LiteralPath $stdout) {
            $observed = Get-Content -LiteralPath $stdout -Raw -ErrorAction SilentlyContinue
            if ($observed -match 'child-only') { break }
        }
    }
    if ($observed -notmatch 'child-only') {
        throw 'II_PS_COMPAT_CHILD_ENV_NOT_PROPAGATED'
    }

    $invalidDenied = $false
    try {
        $options = @{
            Name = 'bad'
            FilePath = $env:ComSpec
            ArgumentList = @('/d','/c','exit 0')
            WorkingDirectory = $root
            LogRoot = $root
            Environment = @{ 'BAD-KEY'='untrusted' }
        }
        Start-IIManagedProcess @options | Out-Null
    } catch { $invalidDenied = $true }
    if (-not $invalidDenied) { throw 'II_PS_COMPAT_INVALID_ENV_ALLOWED' }

    $missingDenied = $false
    try {
        $options = @{
            Name = 'missing'
            FilePath = (Join-Path $root 'missing-executable.exe')
            ArgumentList = @('unused')
            WorkingDirectory = $root
            LogRoot = $root
            Environment = @{ II_TEST_CHILD_MARKER='must-not-leak' }
        }
        Start-IIManagedProcess @options | Out-Null
    } catch { $missingDenied = $true }
    if (-not $missingDenied) { throw 'II_PS_COMPAT_START_FAILURE_NOT_DENIED' }
    if ([Environment]::GetEnvironmentVariable('II_TEST_CHILD_MARKER','Process') -ne 'parent-original') {
        throw 'II_PS_COMPAT_FAILED_START_ENV_LEAK'
    }
    Write-Host "II_PS_RUNTIME_COMPAT=PASS;ENGINE=$($PSVersionTable.PSVersion.ToString())"
} finally {
    [Environment]::SetEnvironmentVariable('II_TEST_CHILD_MARKER',$old,'Process')
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
