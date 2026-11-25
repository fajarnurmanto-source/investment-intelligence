$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
. (Join-Path $root 'tools\production\II-Production.Common.ps1')
$temp = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temp | Out-Null
try {
    $context = [pscustomobject]@{ HabitatRoot = $temp; ProcessManifest = (Join-Path $temp 'processes.json'); AppUrl = 'http://127.0.0.1:8810' }
    $first = Enter-IIRuntimeLock -Context $context
    try {
        $second = Enter-IIRuntimeLock -Context $context
        try {
            Write-IIProcessManifest -Context $context -Manifest @{url=$context.AppUrl; processes=@(); commit='one'}
            Write-IIProcessManifest -Context $context -Manifest @{url=$context.AppUrl; processes=@(); commit='two'}
            if ((Read-IIProcessManifest -Context $context).commit -ne 'two') { throw 'MANIFEST_REPLACE_FAILED' }
            if (@(Get-ChildItem $temp -Filter '*.tmp').Count -ne 0) { throw 'MANIFEST_TEMP_LEAK' }
        } finally { Exit-IIRuntimeLock -Mutex $second }
    } finally { Exit-IIRuntimeLock -Mutex $first }
    function Get-NetTCPConnection { param($LocalPort,$State) }
    if ((Get-IIRuntimeStatus -Context $context).state -ne 'STOPPED') { throw 'STOPPED_STATE_FAILED' }
    function Get-NetTCPConnection { param($LocalPort,$State) [pscustomobject]@{ OwningProcess=999; LocalAddress='127.0.0.1' } }
    if ((Get-IIRuntimeStatus -Context $context).state -ne 'UNMANAGED_LISTENER') { throw 'UNMANAGED_STATE_FAILED' }
    foreach ($name in @('open-ii.ps1','status-ii.ps1','start-ii.ps1','stop-ii.ps1','restart-ii.ps1')) {
        $tokens = $null; $errors = $null
        [void][System.Management.Automation.Language.Parser]::ParseFile((Join-Path $root $name),[ref]$tokens,[ref]$errors)
        if ($errors.Count -gt 0) { throw "PARSE_FAILED: $name" }
    }
    Write-Host 'II_RUNTIME_LIFECYCLE_TEST=PASS'
} finally { Remove-Item -LiteralPath $temp -Recurse -Force }
