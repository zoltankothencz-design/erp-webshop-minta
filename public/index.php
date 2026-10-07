<?php
declare(strict_types=1);

// Fejlesztői (php -S) szerver: a valódi, public/ alatti statikus fájlokat (css, js, kép) közvetlenül szolgálja ki.
// Éles Apache/Nginx mellett ezt a webszerver végzi (lásd public/.htaccess).
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = realpath(__DIR__ . $path);
    if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require __DIR__ . '/../src/bootstrap.php';

use App\Request;
use App\Response;
use App\Router;
use App\View;

$request = Request::fromGlobals();
try {
    $router = new Router();
    (require APP_ROOT . '/src/routes.php')($router);
    $router->dispatch($request)->send();
} catch (Throwable $e) {
    // A részletes hiba csak a naplóba kerül; a látogató általános üzenetet kap (nem szivárog ki belső információ).
    error_log(sprintf('%s %s -> %s: %s in %s:%d', $request->method, $request->path, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    $isApi = str_starts_with($request->path, '/api') || str_starts_with($request->path, '/admin/api');
    ($isApi
        ? Response::json(['error' => 'Szerverhiba történt, kérjük próbálja újra később.'], 500)
        : Response::html(View::page('500', [], 'Hiba'), 500)
    )->send();
}
