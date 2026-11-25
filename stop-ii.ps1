param([ValidateRange(1024, 65535)][int]$Port = 8810)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'tools\production\II-Production.Common.ps1')
$context = Get-IIProductionContext -RepositoryRoot $PSScriptRoot -Port $Port
$manifest = Read-IIProcessManifest -Context $context
if (-not $manifest) {
    # Never claim an unmanaged process is stopped just because its manifest
    # is missing. Deployment must not proceed while its target port is busy.
    if (Test-IIListeningPort -Port $Port) {
        throw "II_STOP_UNMANAGED_LISTENER: port $Port is still occupied but no managed manifest exists."
    }
    Write-Host 'Investment Intelligence is not running (no managed process manifest).'
    return
}

foreach ($entry in @($manifest.processes)) {
    $processId = [int]$entry.id
    $managedProcess = Get-IIManagedProcess -Entry $entry
    if ($managedProcess) {
        Stop-Process -Id $processId -Force -ErrorAction Stop
        for ($attempt = 0; $attempt -lt 60 -and (Test-IIProcess -ProcessId $processId); $attempt++) {
            Start-Sleep -Milliseconds 500
        }
        if (Test-IIProcess -ProcessId $processId) { throw "Managed process $processId did not exit." }
    }
}

for ($attempt = 0; $attempt -lt 60; $attempt++) {
    $portOpen = Test-IIListeningPort -Port $Port
    if (-not $portOpen) { break }
    Start-Sleep -Milliseconds 500
}
if ($portOpen) { throw "Port $Port remained open after stopping managed processes." }
Remove-Item -LiteralPath $context.ProcessManifest -Force -ErrorAction SilentlyContinue
Write-Host 'Investment Intelligence stopped; all managed processes exited.'
