param(
    [Parameter(Mandatory)][ValidateSet('protect','unprotect')][string]$Operation
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
# Windows PowerShell 5.1 does not reliably auto-load the assembly for ProtectedData.
Add-Type -AssemblyName System.Security

# Private local helper invoked by PHP using stdin/stdout. No secret is passed
# through command-line arguments, temporary files, environment or log output.
$inputBytes = $null
$outputBytes = $null
$entropy = $null
try {
    $encoded = [Console]::In.ReadToEnd().Trim()
    if ($encoded.Length -lt 4 -or $encoded.Length -gt 16384 -or
        $encoded -notmatch '^[A-Za-z0-9+/]+={0,2}$') {
        throw 'II_DPAPI_INPUT_REJECTED'
    }
    $inputBytes = [Convert]::FromBase64String($encoded)
    $entropy = [Text.Encoding]::UTF8.GetBytes('InvestmentIntelligence.BYOK.CurrentUser.v1')
    if ($Operation -eq 'protect') {
        $outputBytes = [Security.Cryptography.ProtectedData]::Protect(
            $inputBytes, $entropy, [Security.Cryptography.DataProtectionScope]::CurrentUser
        )
    } else {
        $outputBytes = [Security.Cryptography.ProtectedData]::Unprotect(
            $inputBytes, $entropy, [Security.Cryptography.DataProtectionScope]::CurrentUser
        )
    }
    [Console]::Out.Write([Convert]::ToBase64String($outputBytes))
} catch {
    # Do not echo .NET exceptions, which may include identifying context.
    [Console]::Error.Write('II_DPAPI_OPERATION_FAILED')
    exit 1
} finally {
    if ($null -ne $inputBytes) { [Array]::Clear($inputBytes,0,$inputBytes.Length) }
    if ($null -ne $outputBytes) { [Array]::Clear($outputBytes,0,$outputBytes.Length) }
    if ($null -ne $entropy) { [Array]::Clear($entropy,0,$entropy.Length) }
}
