<?php
declare(strict_types=1);

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

    // Termék-feedek (Google Merchant Center, marketplace-ek)
    $feeds = new FeedController();
    $router->get('/feed/google.xml', [$feeds, 'google']);
    $router->get('/feed/marketplace.xml', [$feeds, 'marketplace']);
};
