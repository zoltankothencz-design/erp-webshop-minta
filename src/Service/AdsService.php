<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\Integration\GoogleAdsClient;

/**
 * A hirdetési adatok két irányban:
 *   Google Ads -> mi : kampányköltség és kattintás (syncStats), hogy egy helyen lássuk a költséget és a bevételt
 *   mi -> Google Ads : a webshop valódi rendeléseit offline konverzióként visszaküldjük (uploadConversions),
 *                      így a Google a tényleges bevételre tud optimalizálni, nem csak a kattintásokra
 *
 * Adatvédelem: a gclid személyes adatnak minősülhet; feltöltés csak olyan vásárlóktól, akik a hirdetési célú
 * követéshez hozzájárultak. Ebben a mintában nincs süti-hozzájárulási felület, élesben kell.
 */
final class AdsService
{
    public function __construct(private GoogleAdsClient $ads)
    {
    }

    /** @return array{items:int, message:string} */
    public function syncStats(int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $rows = $this->ads->campaignStats(date('Y-m-d', strtotime("-$days days")), date('Y-m-d'));
        foreach ($rows as $r) {
            Db::run(
                'INSERT INTO campaigns (external_id, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)',
                [$r['campaign_id'], $r['campaign_name']]
            );
            $campaignId = (int) Db::value('SELECT id FROM campaigns WHERE external_id = ?', [$r['campaign_id']]);
            // A Google utólag is módosítja a friss napok adatát (konverziók késve érkeznek), ezért felülírjuk, nem csak beszúrjuk.
            Db::run(
                'INSERT INTO campaign_stats (campaign_id, stat_date, impressions, clicks, cost, conversions, conv_value)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks), cost = VALUES(cost),
                    conversions = VALUES(conversions), conv_value = VALUES(conv_value)',
                [$campaignId, $r['date'], $r['impressions'], $r['clicks'], $r['cost'], $r['conversions'], $r['conv_value']]
            );
        }
        return ['items' => count($rows), 'message' => count($rows) . ' kampány-nap adata frissítve (utolsó ' . $days . ' nap)'];
    }

    /** @return array{items:int, message:string} */
    public function uploadConversions(): array
    {
        // A gclid kb. 90 napig használható; egy rendelést csak egyszer töltünk fel (conversion_uploads egyedi kulcs).
        $orders = Db::all(
            "SELECT o.id, o.order_no, o.gclid, o.total, o.created_at FROM orders o
             LEFT JOIN conversion_uploads u ON u.order_id = o.id
             WHERE o.gclid IS NOT NULL AND o.status <> 'cancelled' AND u.id IS NULL
               AND o.created_at > NOW() - INTERVAL 90 DAY
             ORDER BY o.id LIMIT 200"
        );
        if ($orders === []) {
            return ['items' => 0, 'message' => 'Nincs feltöltendő konverzió'];
        }
        $payload = array_map(fn($o) => [
            'gclid' => $o['gclid'],
            'order_ref' => $o['order_no'],
            'time' => (new \DateTimeImmutable($o['created_at']))->format('Y-m-d H:i:sP'),
            'value' => (int) $o['total'],
            'currency' => 'HUF',
        ], $orders);

        $results = $this->ads->uploadClickConversions($payload);
        $ok = 0;
        foreach ($orders as $i => $o) {
            $r = $results[$i];
            Db::run(
                'INSERT INTO conversion_uploads (order_id, gclid, value, status, message) VALUES (?, ?, ?, ?, ?)',
                [$o['id'], $o['gclid'], $o['total'], $r['ok'] ? 'uploaded' : 'failed', $r['message']]
            );
            $ok += $r['ok'] ? 1 : 0;
        }
        $failed = count($orders) - $ok;
        return ['items' => $ok, 'message' => "$ok konverzió feltöltve" . ($failed ? ", $failed sikertelen (lásd conversion_uploads)" : '')];
    }
}
