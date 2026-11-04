<?php

/**
 * Vercel entrypoint for Laravel (vercel-php runtime).
 *
 * The deployment filesystem is read-only except /tmp, so everything Laravel
 * writes (compiled views, caches, logs) is redirected there. Values already set
 * in the Vercel dashboard take precedence.
 */
$defaults = [
    'APP_CONFIG_CACHE'   => '/tmp/config.php',
    'APP_EVENTS_CACHE'   => '/tmp/events.php',
    'APP_PACKAGES_CACHE' => '/tmp/packages.php',
    'APP_ROUTES_CACHE'   => '/tmp/routes.php',
    'APP_SERVICES_CACHE' => '/tmp/services.php',
    'VIEW_COMPILED_PATH' => '/tmp/views',
    'LARAVEL_STORAGE_PATH' => '/tmp/storage',
    'LOG_CHANNEL'        => 'stderr',
];

foreach ($defaults as $key => $value) {
    if (getenv($key) === false) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

$storage = getenv('LARAVEL_STORAGE_PATH') ?: '/tmp/storage';
foreach (['app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir("{$storage}/{$dir}")) {
        @mkdir("{$storage}/{$dir}", 0777, true);
    }
}
if (! is_dir('/tmp/views')) {
    @mkdir('/tmp/views', 0777, true);
}

require __DIR__ . '/../public/index.php';
