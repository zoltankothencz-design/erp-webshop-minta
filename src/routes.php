<?php
declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\ApiController;
use App\Controller\FeedController;
use App\Controller\PageController;
use App\Router;

/** Az összes útvonal egy helyen: ebből látszik, mit tud az alkalmazás kívülről. */
return function (Router $router): void {
    $pages = new PageController();
    $api = new ApiController();

    // Oldalak
    $router->get('/', [$pages, 'home']);
    $router->get('/product/{slug}', [$pages, 'product']);
    $router->get('/cart', [$pages, 'cart']);

    // Nyilvános REST API
    $router->get('/api/products', [$api, 'products']);
    $router->get('/api/products/{slug}', [$api, 'product']);
    $router->get('/api/categories', [$api, 'categories']);
    $router->post('/api/orders', [$api, 'createOrder']);

    // Admin / marketing információs felület (belépés kell)
    $admin = new AdminController();
    $router->get('/admin/login', [$admin, 'loginForm']);
    $router->post('/admin/login', [$admin, 'login']);
    $router->post('/admin/logout', [$admin, 'logout']);
    $router->get('/admin', [$admin, 'dashboard']);
    $router->get('/admin/api/summary', [$admin, 'summary']);
    $router->post('/admin/api/jobs/{name}', [$admin, 'runJob']);
    $router->post('/admin/api/ai/{id}/{action}', [$admin, 'reviewAi']);

    // Termék-feedek (Google Merchant Center, marketplace-ek)
    $feeds = new FeedController();
    $router->get('/feed/google.xml', [$feeds, 'google']);
    $router->get('/feed/marketplace.xml', [$feeds, 'marketplace']);
};
