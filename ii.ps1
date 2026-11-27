# Canonical production Artisan launcher, independent of caller working directory.
# No parameter binding: preserve native Artisan options including --user and --version.
$ErrorActionPreference = 'Stop'
$arguments = @($args)
try {
    if ($arguments.Count -lt 2 -or $arguments[0] -ne 'artisan') {
        throw 'II_LAUNCHER_USAGE: ii.ps1 artisan <command> [arguments]'
    }
    $artisanArgs = @($arguments | Select-Object -Skip 1)
    . (Join-Path $PSScriptRoot 'tools/production/II-Production.Common.ps1')
    $context = Get-IIProductionContext -RepositoryRoot $PSScriptRoot -Port 8810
    $php = Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $php) { throw 'II_LAUNCHER_PHP_MISSING' }
    if (-not (Test-Path -LiteralPath (Join-Path $context.AppRoot 'artisan') -PathType Leaf)) {
        throw 'II_LAUNCHER_ARTISAN_MISSING'
    }
    if (-not (Test-Path -LiteralPath $context.EnvironmentPath -PathType Leaf) -or
        -not (Test-Path -LiteralPath $context.DatabasePath -PathType Leaf) -or
        -not (Test-Path -LiteralPath (Join-Path $context.VendorRoot 'autoload.php') -PathType Leaf)) {
        throw 'II_LAUNCHER_RUNTIME_INCOMPLETE'
    }
    $expectedDb = $context.DatabasePath.Replace('\', '/')
    $configuredDb = Get-IIDotEnvValue -Path $context.EnvironmentPath -Name 'DB_DATABASE'
    if ((Get-IIDotEnvValue -Path $context.EnvironmentPath -Name 'APP_ENV') -ne 'production' -or
        (Get-IIDotEnvValue -Path $context.EnvironmentPath -Name 'DB_CONNECTION') -ne 'sqlite' -or
        $configuredDb -ne $expectedDb -or
        [string]::IsNullOrWhiteSpace((Get-IIDotEnvValue -Path $context.EnvironmentPath -Name 'APP_KEY'))) {
        throw 'II_LAUNCHER_PRODUCTION_CONFIG_MISMATCH'
    }
    $names = @('II_RUNTIME_ROOT','II_VENDOR_PATH','COMPOSER_VENDOR_DIR','APP_BASE_PATH','APP_SERVICES_CACHE',
        'APP_PACKAGES_CACHE','APP_CONFIG_CACHE','APP_ROUTES_CACHE','APP_EVENTS_CACHE','II_BIND_ADDRESS',
        'II_PUBLIC_ROOT','APP_ENV','DB_CONNECTION','DB_DATABASE','DB_URL')
    $previous = @{}
    foreach ($name in $names) {
        $previous[$name] = [Environment]::GetEnvironmentVariable($name,'Process')
    }
    try {
        Set-IIProductionEnvironment -Context $context
        $env:APP_ENV = 'production'
        $env:DB_CONNECTION = 'sqlite'
        $env:DB_DATABASE = $expectedDb
        [Environment]::SetEnvironmentVariable('DB_URL',$null,'Process')
        Push-Location -LiteralPath $context.AppRoot
        try {
            # Validate the resolved Laravel configuration, including cached config,
            # before any requested command may touch storage.
            $probe = @'
require getenv('II_VENDOR_PATH').'/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = $app['config']->get('database.connections.sqlite.database');
$url = $app['config']->get('database.connections.sqlite.url');
if ($app->environment() !== 'production' || $app['config']->get('database.default') !== 'sqlite' || !is_string($db) || str_replace(chr(92), '/', $db) !== getenv('DB_DATABASE') || !empty($url)) { exit(71); }
echo 'II_LAUNCHER_CONTEXT=PASS';
'@
            $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($probe))
            $verification = @(& $php.Source -r "eval(base64_decode('$encoded'));")
            if ($LASTEXITCODE -ne 0 -or ($verification -join '') -ne 'II_LAUNCHER_CONTEXT=PASS') {
                throw 'II_LAUNCHER_RESOLVED_CONFIG_MISMATCH'
            }
            & $php.Source artisan @artisanArgs
            $nativeExit = $LASTEXITCODE
        } finally { Pop-Location }
    } finally {
        foreach ($name in $names) {
            [Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')
        }
    }
    exit $nativeExit
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
