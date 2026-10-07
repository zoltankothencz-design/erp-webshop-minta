<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// Egyszerű PSR-4 stílusú autoloader: App\Service\OrderService -> src/Service/OrderService.php
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';

App\Config::load(APP_ROOT . '/.env');
date_default_timezone_set('Europe/Budapest');
ini_set('error_log', APP_ROOT . '/storage/logs/php.log');
