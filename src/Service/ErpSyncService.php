<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\Integration\ErpClient;
use App\Integration\IntegrationException;
use App\SyncLog;

/**
 * Az ERP és a webshop közti adatáramlás:
 *   ERP -> webshop : termékek, ár, készlet  (syncProducts)
 *   webshop -> ERP : új rendelések           (pushOrders)
 * Az ERP a törzsadat ura: ár, név, készlet onnan jön, a webshop nem írja felül.
 */
final class ErpSyncService
{
    public function __construct(private ErpClient $erp)
    {
    }

    /** @return array{items:int, message:string} */
    public function syncProducts(): array
    {
        $since = SyncLog::getState('erp_products_since');
        $pending = $this->pendingQuantities();
        $count = 0;
        $maxUpdated = $since;

        foreach ($this->erp->products($since) as $p) {
            $categoryId = $this->categoryId((string) ($p['category'] ?? ''));
            $erpStock = max(0, (int) $p['stock']);
            $id = (int) Db::value('SELECT id FROM products WHERE erp_id = ?', [$p['erp_id']]);
            // Eladható készlet = ERP készlet - azok a webes rendelések, amelyeket az ERP még nem látott
            $sellable = max(0, $erpStock - ($pending[$id] ?? 0));

            Db::run(
                'INSERT INTO products (erp_id, sku, name, slug, category_id, brand, ean, price, erp_stock, stock, active, description, erp_updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE sku = VALUES(sku), name = VALUES(name), category_id = VALUES(category_id), brand = VALUES(brand),
                    ean = VALUES(ean), price = VALUES(price), erp_stock = VALUES(erp_stock), stock = VALUES(stock),
                    active = VALUES(active), description = VALUES(description), erp_updated_at = VALUES(erp_updated_at)',
                [
                    $p['erp_id'], $p['sku'], $p['name'], slugify($p['name'] . '-' . $p['sku']), $categoryId, $p['brand'] ?? null, $p['ean'] ?? null,
                    (int) $p['price'], $erpStock, $sellable, !empty($p['active']) ? 1 : 0, $p['description'] ?? null, $p['updated_at'] ?? null,
                ]
            );
            $count++;
            if (isset($p['updated_at']) && ($maxUpdated === null || $p['updated_at'] > $maxUpdated)) {
                $maxUpdated = $p['updated_at'];
            }
        }
        // A "mikortól" kurzort a kapott adatból vesszük, nem a saját órából: így az órák eltérése nem veszít el változást.
        if ($maxUpdated !== null) {
            SyncLog::setState('erp_products_since', $maxUpdated);
        }
        return ['items' => $count, 'message' => "$count termék szinkronizálva az ERP-ből"];
    }

    /** @return array{items:int, message:string} */
    public function pushOrders(): array
    {
        $orders = Db::all("SELECT * FROM orders WHERE status = 'new' AND erp_order_id IS NULL ORDER BY id LIMIT 50");
        $sent = 0;
        $failed = [];
        foreach ($orders as $order) {
            $items = Db::all('SELECT sku, name, unit_price, qty FROM order_items WHERE order_id = ?', [$order['id']]);
            try {
                $res = $this->erp->createOrder([
                    'order_no' => $order['order_no'],
                    'source' => $order['source'],
                    'customer' => ['name' => $order['customer_name'], 'email' => $order['email'], 'phone' => $order['phone'], 'address' => $order['address']],
                    'items' => $items,
                    'total' => (int) $order['total'],
                ], $order['order_no']);   // az order_no az idempotencia-kulcs: ugyanaz a rendelés kétszer sem jön létre az ERP-ben
                Db::run("UPDATE orders SET erp_order_id = ?, status = 'sent_to_erp' WHERE id = ?", [$res['erp_order_id'], $order['id']]);
                $sent++;
            } catch (IntegrationException $e) {
                // Egy hibás rendelés nem állíthatja meg a többit; a következő futáskor újra próbáljuk.
                $failed[] = $order['order_no'];
                error_log('ERP rendelés-küldés sikertelen (' . $order['order_no'] . '): ' . $e->getMessage());
            }
        }
        $msg = "$sent rendelés elküldve az ERP-nek";
        if ($failed) {
            $msg .= '; sikertelen: ' . implode(', ', $failed);
        }
        if ($failed && $sent === 0) {
            throw new IntegrationException($msg);
        }
        return ['items' => $sent, 'message' => $msg];
    }

    /** @return array<int,int> termék-id => még az ERP-nek el nem küldött (foglalt) darabszám */
    private function pendingQuantities(): array
    {
        $rows = Db::all(
            "SELECT oi.product_id, SUM(oi.qty) AS qty FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.status = 'new' AND o.erp_order_id IS NULL AND oi.product_id IS NOT NULL GROUP BY oi.product_id"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['product_id']] = (int) $r['qty'];
        }
        return $out;
    }

    private function categoryId(string $name): ?int
    {
        if ($name === '') {
            return null;
        }
        $slug = slugify($name);
        Db::run('INSERT INTO categories (name, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)', [$name, $slug]);
        return (int) Db::value('SELECT id FROM categories WHERE slug = ?', [$slug]);
    }
}
