# Disposable habitat; no real production database or credentials.
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path
$root = Join-Path ([IO.Path]::GetTempPath()) ('ii-launcher-'+[Guid]::NewGuid().ToString('N'))
$copy = Join-Path $root 'repository'
$runtime = Join-Path $root 'runtime/laravel'
$exe = (Get-Process -Id $PID).Path
try {
    foreach ($dir in @("$copy/tools/production", "$copy/laravel_app/bootstrap", "$runtime/database", "$runtime/vendor")) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    Copy-Item (Join-Path $repo 'ii.ps1') $copy
    Copy-Item (Join-Path $repo 'tools/production/II-Production.Common.ps1') "$copy/tools/production"
    $db = (Join-Path $runtime 'database/ii.sqlite').Replace('\','/')
    New-Item -ItemType File -Path $db | Out-Null
    @('APP_ENV=production','DB_CONNECTION=sqlite',('DB_DATABASE="'+$db+'"'),'APP_KEY=synthetic_fixture') |
        Set-Content -LiteralPath "$runtime/.env" -Encoding ascii
    # A minimal isolated probe fixture, not a real Laravel installation.
    @'
<?php
namespace Illuminate\Contracts\Console { interface Kernel {} }
namespace {
class FixtureConfig {
    function get($key) {
        return match($key) {
            'database.default' => getenv('DB_CONNECTION'),
            'database.connections.sqlite.database' => getenv('DB_DATABASE'),
            default => null
        };
    }
}
class FixtureApp implements \ArrayAccess {
    function make($class) { return $this; }
    function bootstrap() {}
    function environment() { return getenv('APP_ENV'); }
    function offsetExists(mixed $offset): bool { return true; }
    function offsetGet(mixed $offset): mixed { return new FixtureConfig(); }
    function offsetSet(mixed $offset, mixed $value): void {}
    function offsetUnset(mixed $offset): void {}
}
}
'@ | Set-Content -LiteralPath "$runtime/vendor/autoload.php" -Encoding ascii
    '<?php return new FixtureApp();' | Set-Content -LiteralPath "$copy/laravel_app/bootstrap/app.php" -Encoding ascii
    @'
<?php
if (basename(getcwd()) !== 'laravel_app' || getenv('DB_CONNECTION') !== 'sqlite') { exit(72); }
echo implode('|',array_slice($argv,1));
exit(in_array('fail',$argv,true) ? 23 : 0);
'@ | Set-Content -LiteralPath "$copy/laravel_app/artisan" -Encoding ascii
    Push-Location -LiteralPath $root
    try {
        $output = @(& $exe -NoProfile -ExecutionPolicy Bypass -File "$copy/ii.ps1" artisan sample --user=7 --rights-confirmed)
        if ($LASTEXITCODE -ne 0 -or ($output -join '') -ne 'sample|--user=7|--rights-confirmed') {
            throw 'II_LAUNCHER_ARGUMENT_OR_CWD_FAILED'
        }
        & $exe -NoProfile -ExecutionPolicy Bypass -File "$copy/ii.ps1" artisan fail | Out-Null
        if ($LASTEXITCODE -ne 23) { throw 'II_LAUNCHER_EXIT_CODE_FAILED' }
        Remove-Item -LiteralPath $db
        $failed = $false
        try {
            & $exe -NoProfile -ExecutionPolicy Bypass -File "$copy/ii.ps1" artisan sample 2>&1 | Out-Null
            $failed = $LASTEXITCODE -ne 0
        } catch { $failed = $true }
        if (-not $failed) { throw 'II_LAUNCHER_MISSING_DB_NOT_DENIED' }
    } finally { Pop-Location }
    Write-Host "II_ARTISAN_LAUNCHER=PASS;ENGINE=$($PSVersionTable.PSVersion)"
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
