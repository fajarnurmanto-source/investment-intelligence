<?php

use Composer\Autoload\ClassLoader;

$basePath = dirname(__DIR__);
$vendorPath = getenv('II_VENDOR_PATH') ?: $basePath.'/vendor';

/** @var ClassLoader $loader */
$loader = require rtrim($vendorPath, "\\/").'/autoload.php';

// Composer normally assumes that vendor/ is inside the project. II keeps
// installed dependencies in the habitat runtime, so project namespaces are
// rebound explicitly while package namespaces continue using Composer data.
$loader->setPsr4('App\\', [$basePath.'/app']);
$loader->setPsr4('Database\\Factories\\', [$basePath.'/database/factories']);
$loader->setPsr4('Database\\Seeders\\', [$basePath.'/database/seeders']);
$loader->setPsr4('Tests\\', [$basePath.'/tests']);

$projectClassMap = [];
foreach ($loader->getClassMap() as $class => $path) {
    if (preg_match('~[\\\\/](app|database|tests)[\\\\/](.+)$~', $path, $matches) !== 1) {
        continue;
    }

    $candidate = $basePath.'/'.$matches[1].'/'.str_replace('\\', '/', $matches[2]);
    if (is_file($candidate)) {
        $projectClassMap[$class] = $candidate;
    }
}
$loader->addClassMap($projectClassMap);

return $loader;
