<?php
declare(strict_types=1);

namespace App\Service;

use App\Integration\Integrations;
use App\SyncLog;

/**
 * Az integrációs feladatok egységes futtatója. Ugyanezt hívja a parancssor (cron), és az admin felület gombja is.
 * Minden futás naplózódik a sync_log táblába, és a hiba nem dobja el az egész folyamatot, csak "error" státuszt kap.
 */
final class JobRunner
{
    /** @return array<string, callable():array{items:int,message:string}> */
    private static function jobs(): array
    {
        return [
            'erp-products' => fn() => (new ErpSyncService(Integrations::erp()))->syncProducts(),
            'erp-orders' => fn() => (new ErpSyncService(Integrations::erp()))->pushOrders(),
            'marketplace-orders' => fn() => (new MarketplaceService(Integrations::marketplace()))->importOrders(),
            'marketplace-offers' => fn() => (new MarketplaceService(Integrations::marketplace()))->pushOffers(),
            'ads-stats' => fn() => (new AdsService(Integrations::ads()))->syncStats(),
            'ads-conversions' => fn() => (new AdsService(Integrations::ads()))->uploadConversions(),
            'ai-descriptions' => fn() => (new ProductDescriptionService(Integrations::ai()))->generateMissing(5),
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::jobs());
    }

    /** @return array{status:string,message:string,items:int} */
    public static function run(string $name): array
    {
        $jobs = self::jobs();
        if (!isset($jobs[$name])) {
            return ['status' => 'error', 'message' => "Ismeretlen feladat: $name", 'items' => 0];
        }
        $logId = SyncLog::start($name);
        try {
            $r = $jobs[$name]();
            SyncLog::finish($logId, 'ok', $r['message'], $r['items']);
            return ['status' => 'ok', 'message' => $r['message'], 'items' => $r['items']];
        } catch (\Throwable $e) {
            SyncLog::finish($logId, 'error', $e->getMessage());
            error_log("Job $name hibára futott: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage(), 'items' => 0];
        }
    }
}
