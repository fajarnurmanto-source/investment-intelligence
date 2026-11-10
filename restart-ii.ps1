param(
    [ValidateRange(1024, 65535)][int]$Port = 8810,
    [switch]$SkipMigrate
)
$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'stop-ii.ps1') -Port $Port
& (Join-Path $PSScriptRoot 'start-ii.ps1') -Port $Port -SkipMigrate:$SkipMigrate
