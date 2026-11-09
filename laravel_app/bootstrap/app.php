<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Laravel's default absolute-cache prefixes are Unix-oriented. Supporting
// drive-letter paths lets Windows operators place writable runtime caches in
// an isolated temp directory without changing source permissions.
if (PHP_OS_FAMILY === 'Windows') {
    foreach (range('A', 'Z') as $drive) {
        $application->addAbsoluteCachePathPrefix($drive.':\\');
    }
}

return $application;
