<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\Integration\IntegrationException;
use App\Integration\MarketplaceClient;
use App\SyncLog;

/**
 * Marketplace kapcsolat, három irányban:
 *   marketplace -> mi : rendelések importja (importOrders)
 *   mi -> marketplace : ár és készlet frissítése (pushOffers)
 *   mi -> marketplace : termék-feed (lásd FeedExporter, a marketplace húzza le)
 */
final class MarketplaceService
{
    public function __construct(private MarketplaceClient $client, private OrderService $orders = new OrderService())
    {
    }

    /** @return array{items:int, message:string} */
    public function importOrders(): array
    {
        $cursor = SyncLog::getState('mp_orders_since');
        // 1 másodperccel korábbról kérdezünk: az azonos másodpercben érkezett rendelés se maradjon ki.
        // A duplikátumokat az adatbázis egyedi kulcsa (source + marketplace_ref) kiszűri, így ez veszélytelen.
        $since = $cursor !== null ? date('Y-m-d H:i:s', strtotime($cursor) - 1) : null;
        $orders = $this->client->orders($since);
        usort($orders, fn($a, $b) => strcmp((string) $a['created_at'], (string) $b['created_at']));

        $created = 0;
        $duplicates = 0;
        $lastOk = $cursor;
        $problem = null;
        foreach ($orders as $o) {
            try {
                $result = $this->orders->importMarketplaceOrder($o);
                $result === 'created' ? $created++ : $duplicates++;
                $lastOk = (string) $o['created_at'];
            } catch (\Throwable $e) {
                // Hibás rendelésnél megállunk, a kurzor nem lép tovább: a következő futás újra megpróbálja,
                // és így semmi nem vész el csendben.
                $problem = ($o['marketplace_ref'] ?? '?') . ': ' . $e->getMessage();
                break;
            }
        }
        if ($lastOk !== null) {
            SyncLog::setState('mp_orders_since', $lastOk);
        }
        $msg = "$created új marketplace rendelés, $duplicates már ismert";
        if ($problem !== null) {
            throw new IntegrationException("$msg; HIBA a következő rendelésnél, a feldolgozás megállt: $problem");
        }
        return ['items' => $created, 'message' => $msg];
    }

    /** @return array{items:int, message:string} */
    public function pushOffers(): array
    {
        $since = SyncLog::getState('mp_offers_since') ?? '1970-01-01 00:00:00';
        $rows = Db::all('SELECT sku, price, stock, updated_at FROM products WHERE active = 1 AND updated_at > ? ORDER BY updated_at LIMIT 200', [$since]);
        $ok = 0;
        $failed = [];
        $last = $since;
        foreach ($rows as $r) {
            try {
                $this->client->updateOffer($r['sku'], (int) $r['price'], (int) $r['stock']);
                $ok++;
                $last = $r['updated_at'];
            } catch (IntegrationException $e) {
                $failed[] = $r['sku'];
                break;   // sorrendben haladunk; hibánál megállunk, a kurzor az utolsó sikeresnél marad
            }
        }
        SyncLog::setState('mp_offers_since', $last);
        if ($failed) {
            throw new IntegrationException("$ok ajánlat frissítve, de hiba történt (" . $failed[0] . "), a többi a következő futásra marad.");
        }
        return ['items' => $ok, 'message' => "$ok ajánlat (ár/készlet) frissítve a marketplace-en"];
    }
}
