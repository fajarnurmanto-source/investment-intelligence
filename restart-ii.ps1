param(
    [ValidateRange(1024, 65535)][int]$Port = 8810,
    [switch]$SkipMigrate
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'tools\production\II-Production.Common.ps1')
$context = Get-IIProductionContext -RepositoryRoot $PSScriptRoot -Port $Port
$mutex = Enter-IIRuntimeLock -Context $context
try {
& (Join-Path $PSScriptRoot 'stop-ii.ps1') -Port $Port
& (Join-Path $PSScriptRoot 'start-ii.ps1') -Port $Port -SkipMigrate:$SkipMigrate

} finally { Exit-IIRuntimeLock -Mutex $mutex }
