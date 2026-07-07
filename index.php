<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('America/Sao_Paulo');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$config = require __DIR__ . '/config/app.php';
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', (string) ($config['base_url'] ?? ''));
}

require_once __DIR__ . '/app/Core/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$app = new App\Core\App($config);
$app->run();
