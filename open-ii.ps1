param([ValidateRange(1024, 65535)][int]$Port = 8810, [switch]$Browser)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'tools\production\II-Production.Common.ps1')
$context = Get-IIProductionContext -RepositoryRoot $PSScriptRoot -Port $Port
$mutex = Enter-IIRuntimeLock -Context $context
try {
    $status = Get-IIRuntimeStatus -Context $context
    switch ($status.state) {
        'HEALTHY' {}
        'STOPPED' { & (Join-Path $PSScriptRoot 'start-ii.ps1') -Port $Port -SkipMigrate }
        'PARTIAL' { & (Join-Path $PSScriptRoot 'restart-ii.ps1') -Port $Port -SkipMigrate }
        default { throw "II_OPEN_BLOCKED: $($status.state)" }
    }
    if ((Get-IIRuntimeStatus -Context $context).state -ne 'HEALTHY') { throw 'II_OPEN_NOT_HEALTHY' }
    if ($Browser) { Start-Process $context.AppUrl }
    else {
        $edge = @(
            "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
            "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
            "$env:LOCALAPPDATA\Microsoft\Edge\Application\msedge.exe"
        ) | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
        if (-not $edge) { throw 'Microsoft Edge tidak ditemukan. Gunakan open-ii.ps1 -Browser untuk akses sementara.' }
        $profile = Join-Path $context.HabitatRoot 'runtime\desktop-profile'
        New-Item -ItemType Directory -Path $profile -Force | Out-Null
        Start-Process -FilePath $edge -ArgumentList @('--app=' + $context.AppUrl, ('--user-data-dir="' + $profile + '"'), '--no-first-run', '--disable-extensions', '--disable-background-mode', '--no-default-browser-check')
    }
} finally { Exit-IIRuntimeLock -Mutex $mutex }
