# Disposable-only regression for an occupied port with no II process manifest.
# Never touches the real app port, runtime database, or APP_KEY.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..\..')).Path
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = [int]$listener.LocalEndpoint.Port
try {
    $blocked = $false
    try {
        & (Join-Path $repo 'stop-ii.ps1') -Port $port | Out-Null
    } catch {
        if ($_.Exception.Message -match 'II_STOP_UNMANAGED_LISTENER') {
            $blocked = $true
        } else { throw }
    }
    if (-not $blocked) {
        throw 'II_STOP_OCCUPIED_UNOWNED_PORT_NOT_BLOCKED'
    }
} finally {
    $listener.Stop()
}
& (Join-Path $repo 'stop-ii.ps1') -Port $port | Out-Null
Write-Host "II_PS_STOP_UNMANAGED_PORT=PASS;ENGINE=$($PSVersionTable.PSVersion.ToString())"
