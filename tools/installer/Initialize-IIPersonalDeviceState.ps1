param(
    [Parameter(Mandatory)][string]$PackageRoot,
    [Parameter(Mandatory)][string]$DeviceDataRoot,
    [ValidateRange(1024,65535)][int]$Port = 8810,
    [switch]$AllowUnsignedStagingForTest
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Prototype only: never activate unsigned staging outside an explicit test.
if (-not $AllowUnsignedStagingForTest) { throw 'II_DEVICE_UNSIGNED_PACKAGE_NOT_AUTHORIZED' }
$verify = Join-Path $PSScriptRoot 'Test-IIPersonalStagingIntegrity.ps1'
$receipt = (& $verify -PackageRoot $PackageRoot | ConvertFrom-Json)
if ($receipt.status -ne 'STAGED_PAYLOAD_INTEGRITY_VERIFIED' -or $receipt.installable -ne $false) {
    throw 'II_DEVICE_PACKAGE_NOT_VERIFIED'
}

$package = (Resolve-Path -LiteralPath $PackageRoot).Path
$dataRoot = [IO.Path]::GetFullPath($DeviceDataRoot).TrimEnd('\','/')
if ([string]::IsNullOrWhiteSpace($dataRoot)) { throw 'II_DEVICE_ROOT_INVALID' }
$packageBoundary = $package.TrimEnd('\','/') + [IO.Path]::DirectorySeparatorChar
$dataBoundary = $dataRoot + [IO.Path]::DirectorySeparatorChar
if ($dataRoot.Equals($package.TrimEnd('\','/'),[StringComparison]::OrdinalIgnoreCase) -or
    $dataRoot.StartsWith($packageBoundary,[StringComparison]::OrdinalIgnoreCase) -or
    $package.StartsWith($dataBoundary,[StringComparison]::OrdinalIgnoreCase)) {
    throw 'II_DEVICE_ROOT_OVERLAPS_PACKAGE'
}
if (Test-Path -LiteralPath $dataRoot) { throw 'II_DEVICE_DATA_ALREADY_EXISTS' }
$parent = [IO.Path]::GetDirectoryName($dataRoot)
if ([string]::IsNullOrWhiteSpace($parent) -or -not (Test-Path -LiteralPath $parent -PathType Container)) {
    throw 'II_DEVICE_PARENT_MISSING'
}
if ((Get-Item -LiteralPath $parent -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
    throw 'II_DEVICE_PARENT_REPARSE_POINT'
}

$randomBytes = [byte[]]::new(32)
[Security.Cryptography.RandomNumberGenerator]::Fill($randomBytes)
$appKey = 'base64:' + [Convert]::ToBase64String($randomBytes)
$deviceId = [Guid]::NewGuid().ToString('D')
$envDatabasePath = (Join-Path $dataRoot 'database\ii.sqlite').Replace('\','/')
if ($envDatabasePath.Contains('"') -or $envDatabasePath.Contains([char]10)) { throw 'II_DEVICE_UNSAFE_PATH' }

$environmentLines = @(
    'APP_NAME="Investment Intelligence"',
    'APP_ENV=production',
    'APP_DEBUG=false',
    ('APP_KEY=' + $appKey),
    ('APP_URL=http://127.0.0.1:' + $Port),
    'DB_CONNECTION=sqlite',
    ('DB_DATABASE="' + $envDatabasePath + '"'),
    'SESSION_DRIVER=database',
    'CACHE_STORE=database',
    'QUEUE_CONNECTION=database',
    'FILESYSTEM_DISK=local',
    'LOG_CHANNEL=stack',
    'LOG_LEVEL=warning'
)
$installMeta = [ordered]@{
    schema='ii.prt.device-bootstrap.v1'
    device_id=$deviceId
    owner_enrolled=$false
    app_key_created=$true
    database_migrated=$false
    installable=$false
    activation_status='PENDING_DATABASE_MIGRATION_AND_OWNER_ENROLLMENT'
    created_at=[DateTimeOffset]::UtcNow.ToString('o')
}

$staging = Join-Path $parent ('.ii-prt-device-pending-'+[Guid]::NewGuid().ToString('N'))
try {
    New-Item -ItemType Directory -Path $staging -ErrorAction Stop | Out-Null
    # Restrict access BEFORE any APP_KEY material is written. Fail closed if
    # the current Windows identity or DACL cannot be established.
    if ($IsWindows) {
        $currentSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
        if ($null -eq $currentSid) { throw 'II_DEVICE_WINDOWS_OWNER_SID_MISSING' }
        $acl = Get-Acl -LiteralPath $staging
        $acl.SetAccessRuleProtection($true, $false)
        foreach ($rule in @($acl.Access)) { [void]$acl.RemoveAccessRuleAll($rule) }
        $inheritance = [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
        $propagation = [Security.AccessControl.PropagationFlags]::None
        foreach ($sidValue in @($currentSid.Value,'S-1-5-18')) {
            $sid = [Security.Principal.SecurityIdentifier]::new($sidValue)
            $rule = [Security.AccessControl.FileSystemAccessRule]::new(
                $sid,
                [Security.AccessControl.FileSystemRights]::FullControl,
                $inheritance,
                $propagation,
                [Security.AccessControl.AccessControlType]::Allow
            )
            $acl.AddAccessRule($rule)
        }
        Set-Acl -LiteralPath $staging -AclObject $acl
        $effective = Get-Acl -LiteralPath $staging
        if (-not $effective.AreAccessRulesProtected) { throw 'II_DEVICE_ACL_INHERITANCE_NOT_DISABLED' }
    } else {
        throw 'II_DEVICE_WINDOWS_ONLY'
    }
    foreach ($relative in @('database','bootstrap-cache','storage\app\private','storage\app\public',
        'storage\framework\cache\data','storage\framework\sessions','storage\framework\views','storage\logs')) {
        New-Item -ItemType Directory -Path (Join-Path $staging $relative) -Force | Out-Null
    }
    [IO.File]::WriteAllText((Join-Path $staging '.env'),(($environmentLines -join [Environment]::NewLine)+[Environment]::NewLine),[Text.UTF8Encoding]::new($false))
    # SQLite/Laravel migration creates the schema from this empty placeholder.
    [IO.File]::WriteAllBytes((Join-Path $staging 'database\ii.sqlite'),[byte[]]::new(0))
    [IO.File]::WriteAllText((Join-Path $staging 'device.json'),
        ($installMeta | ConvertTo-Json -Depth 5),[Text.UTF8Encoding]::new($false))
    # Atomic new-directory publication; existing state is never overwritten.
    [IO.Directory]::Move($staging,$dataRoot)
} catch {
    if (Test-Path -LiteralPath $staging) { Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue }
    throw
} finally {
    [Array]::Clear($randomBytes,0,$randomBytes.Length)
}
Write-Output ([ordered]@{
    status='PENDING_DATABASE_MIGRATION_AND_OWNER_ENROLLMENT'
    device_id=$deviceId
    data_initialized=$true
    key_printed=$false
    database_migrated=$false
    login_ready=$false
    installer_shipped=$false
} | ConvertTo-Json -Compress)
