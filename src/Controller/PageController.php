<?php
declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Request;
use App\Response;
use App\View;

/** A látogatók számára megjelenő HTML oldalak (szerveroldalon renderelve, így a keresők is látják a tartalmat). */
final class PageController
{
    public function __construct(private ProductRepository $products = new ProductRepository())
    {
    }

    public function home(Request $r): Response
    {
        $category = $r->q('category');
        $q = $r->q('q');
        $rows = $this->products->search($category, $q);
        return Response::html(View::page('home', [
            'products' => array_map($this->products->toPublic(...), $rows),
            'categories' => $this->products->categories(),
            'activeCategory' => $category,
            'q' => $q ?? '',
        ], 'Termékek'));
    }

    public function product(Request $r, array $params): Response
    {
        $row = $this->products->findBySlug($params['slug']);
        if ($row === null) {
            return Response::html(View::page('404', [], 'Nem található'), 404);
        }
        $p = $this->products->toPublic($row);
        return Response::html(View::page('product', ['p' => $p], $p['name']));
    }

    public function cart(): Response
    {
        return Response::html(View::page('cart', [], 'Kosár'));
    }
}
