param([ValidateRange(1024, 65535)][int]$Port = 8810)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'tools\production\II-Production.Common.ps1')
$context = Get-IIProductionContext -RepositoryRoot $PSScriptRoot -Port $Port
$mutex = Enter-IIRuntimeLock -Context $context
try { Get-IIRuntimeStatus -Context $context | ConvertTo-Json -Depth 5 }
finally { Exit-IIRuntimeLock -Mutex $mutex }
