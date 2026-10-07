<?php
declare(strict_types=1);

namespace App\Repository;

use App\Db;

final class ProductRepository
{
    private const SELECT = 'SELECT p.id, p.sku, p.name, p.slug, p.brand, p.ean, p.price, p.stock, p.description, p.description_ai, p.ai_status,
                                   p.image_url, c.name AS category_name, c.slug AS category_slug
                            FROM products p LEFT JOIN categories c ON c.id = p.category_id';

    /**
     * Aktív termékek listája. Minden szűrő paraméterként kerül az SQL-be (nem szövegként összefűzve).
     * @param list<string>|null $skus
     */
    public function search(?string $categorySlug = null, ?string $q = null, ?array $skus = null, int $limit = 100): array
    {
        $where = ['p.active = 1'];
        $params = [];
        if ($categorySlug !== null && $categorySlug !== '') {
            $where[] = 'c.slug = ?';
            $params[] = $categorySlug;
        }
        if ($q !== null && trim($q) !== '') {
            $like = '%' . addcslashes(trim($q), '%_\\') . '%';   // a felhasználó %-jelei ne legyenek joker karakterek
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if ($skus !== null) {
            if ($skus === []) {
                return [];
            }
            $where[] = 'p.sku IN (' . Db::placeholders($skus) . ')';
            array_push($params, ...$skus);
        }
        $limit = max(1, min(500, $limit));
        return Db::all(self::SELECT . ' WHERE ' . implode(' AND ', $where) . " ORDER BY p.name LIMIT $limit", $params);
    }

    public function findBySlug(string $slug): ?array
    {
        return Db::one(self::SELECT . ' WHERE p.active = 1 AND p.slug = ?', [$slug]);
    }

    /** @return list<array{name:string,slug:string,count:int}> */
    public function categories(): array
    {
        return Db::all(
            'SELECT c.name, c.slug, COUNT(p.id) AS count FROM categories c
             JOIN products p ON p.category_id = c.id AND p.active = 1
             GROUP BY c.id ORDER BY c.name'
        );
    }

    /**
     * A látogatónak szánt (publikus) alak. Szándékosan nem adunk ki belső mezőt (erp_id, pontos készlet, ai_status).
     * Az AI-leírás csak jóváhagyás után jelenik meg.
     */
    public function toPublic(array $row): array
    {
        $description = ($row['ai_status'] ?? null) === 'approved' && !empty($row['description_ai'])
            ? $row['description_ai']
            : ($row['description'] ?? '');
        return [
            'sku' => $row['sku'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'brand' => $row['brand'],
            'category' => $row['category_name'],
            'price' => (int) $row['price'],
            'available' => (int) $row['stock'] > 0,
            'max_qty' => max(0, min(20, (int) $row['stock'])),
            'description' => $description,
            'image' => $row['image_url'] ?: '/assets/placeholder.svg',
        ];
    }
}
