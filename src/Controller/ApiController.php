<?php
declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Request;
use App\Response;
use App\Service\OrderService;
use App\ValidationException;

/** A nyilvános webshop REST API-ja (a böngészőben futó JavaScript ezt hívja). */
final class ApiController
{
    public function __construct(
        private ProductRepository $products = new ProductRepository(),
        private OrderService $orders = new OrderService(),
    ) {
    }

    public function products(Request $r): Response
    {
        $skus = null;
        if ($r->q('skus') !== null) {
            $skus = array_slice(array_values(array_filter(array_map('trim', explode(',', (string) $r->q('skus'))))), 0, 50);
        }
        $rows = $this->products->search($r->q('category'), $r->q('q'), $skus);
        return Response::json(['products' => array_map($this->products->toPublic(...), $rows)]);
    }

    public function product(Request $r, array $params): Response
    {
        $row = $this->products->findBySlug($params['slug']);
        return $row === null
            ? Response::json(['error' => 'Nincs ilyen termék'], 404)
            : Response::json(['product' => $this->products->toPublic($row)]);
    }

    public function categories(): Response
    {
        return Response::json(['categories' => $this->products->categories()]);
    }

    public function createOrder(Request $r): Response
    {
        try {
            return Response::json($this->orders->createWebOrder($r->json()), 201);
        } catch (ValidationException $e) {
            return Response::json(['errors' => $e->errors], 422);
        }
    }
}
