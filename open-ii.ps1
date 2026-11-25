param([ValidateRange(1024, 65535)][int]$Port = 8810)
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
    Start-Process $context.AppUrl
} finally { Exit-IIRuntimeLock -Mutex $mutex }
