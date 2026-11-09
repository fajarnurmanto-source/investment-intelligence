param(
    [int]$Port = 8810,
    [switch]$SkipMigrate
)

$ErrorActionPreference = 'Stop'
$appRoot = Join-Path $PSScriptRoot 'laravel_app'
if (-not (Test-Path -LiteralPath (Join-Path $appRoot 'artisan'))) {
    throw 'Laravel application was not found.'
}
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP 8.3 or newer is required.'
}

Push-Location $appRoot
try {
    if (-not (Test-Path -LiteralPath '.env')) {
        Copy-Item -LiteralPath '.env.example' -Destination '.env'
    }
    if (-not (Test-Path -LiteralPath 'database\database.sqlite')) {
        New-Item -ItemType File -Path 'database\database.sqlite' | Out-Null
    }
    if (-not (Test-Path -LiteralPath 'vendor\autoload.php')) {
        & composer install --no-interaction --prefer-dist
        if ($LASTEXITCODE -ne 0) { throw 'Composer install failed.' }
    }
    $appKey = Select-String -LiteralPath '.env' -Pattern '^APP_KEY=.+$' -Quiet
    if (-not $appKey) {
        & php artisan key:generate --force
        if ($LASTEXITCODE -ne 0) { throw 'Laravel key generation failed.' }
    }
    if (-not $SkipMigrate) {
        & php artisan migrate --force
        if ($LASTEXITCODE -ne 0) { throw 'Laravel migration failed.' }
    }

    $queue = Start-Process -FilePath 'php' -ArgumentList @('artisan','queue:work','--tries=3','--timeout=90') -WorkingDirectory $appRoot -WindowStyle Hidden -PassThru
    $scheduler = Start-Process -FilePath 'php' -ArgumentList @('artisan','schedule:work') -WorkingDirectory $appRoot -WindowStyle Hidden -PassThru
    try {
        Write-Host "Investment Intelligence: http://127.0.0.1:$Port"
        & php artisan serve --host=127.0.0.1 --port=$Port
    } finally {
        foreach ($process in @($queue, $scheduler)) {
            if ($process -and -not $process.HasExited) { Stop-Process -Id $process.Id }
        }
    }
} finally {
    Pop-Location
}
