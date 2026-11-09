<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$runtimeRoot = getenv('II_RUNTIME_ROOT');
$storagePath = is_string($runtimeRoot) && trim($runtimeRoot) !== ''
    ? rtrim($runtimeRoot, "\\/").DIRECTORY_SEPARATOR.'storage'
    : __DIR__.'/../storage';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $storagePath.'/framework/maintenance.php')) {
    require $maintenance;
}

// Register Composer packages and the canonical project namespaces...
require __DIR__.'/../bootstrap/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
