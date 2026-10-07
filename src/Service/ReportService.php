<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\SyncLog;

/** A marketing információs rendszer adatai: a bolt eladásai és a hirdetések költsége egy helyen. */
final class ReportService
{
    /** @return array<string,mixed> */
    public function dashboard(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $from = date('Y-m-d', strtotime("-$days days"));

        $bySource = Db::all(
            "SELECT source, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue FROM orders
             WHERE created_at >= ? AND status <> 'cancelled' GROUP BY source",
            [$from]
        );
        $ads = Db::one(
            'SELECT COALESCE(SUM(cost), 0) AS cost, COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(impressions), 0) AS impressions,
                    COALESCE(SUM(conversions), 0) AS conversions, COALESCE(SUM(conv_value), 0) AS conv_value
             FROM campaign_stats WHERE stat_date >= ?',
            [$from]
        );
        // A saját rendelésadatból számolt, hirdetésnek tulajdonítható bevétel (gclid vagy cpc közeg alapján)
        $attributed = Db::one(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue FROM orders
             WHERE created_at >= ? AND status <> 'cancelled' AND (gclid IS NOT NULL OR utm_medium = 'cpc')",
            [$from]
        );
        $cost = (int) $ads['cost'];

        return [
            'days' => $days,
            'orders_by_source' => $bySource,
            'ads' => [
                'cost' => $cost, 'clicks' => (int) $ads['clicks'], 'impressions' => (int) $ads['impressions'],
                'conversions' => (float) $ads['conversions'], 'conv_value_google' => (int) $ads['conv_value'],
                'revenue_shop' => (int) $attributed['revenue'], 'orders_shop' => (int) $attributed['orders'],
                // ROAS = bevétel / hirdetési költség. Két forrásból számoljuk, mert a kettő eltérhet: ez maga is információ.
                'roas_google' => $cost > 0 ? round($ads['conv_value'] / $cost, 2) : null,
                'roas_shop' => $cost > 0 ? round($attributed['revenue'] / $cost, 2) : null,
            ],
            'campaigns' => Db::all(
                'SELECT c.name, SUM(s.impressions) AS impressions, SUM(s.clicks) AS clicks, SUM(s.cost) AS cost,
                        SUM(s.conversions) AS conversions, SUM(s.conv_value) AS conv_value
                 FROM campaigns c JOIN campaign_stats s ON s.campaign_id = c.id
                 WHERE s.stat_date >= ? GROUP BY c.id ORDER BY cost DESC',
                [$from]
            ),
            'recent_orders' => Db::all(
                'SELECT order_no, source, customer_name, total, status, erp_order_id, created_at, utm_campaign, (gclid IS NOT NULL) AS has_gclid
                 FROM orders ORDER BY id DESC LIMIT 15'
            ),
            'order_status' => Db::all('SELECT status, COUNT(*) AS n FROM orders GROUP BY status'),
            'ai_drafts' => Db::all("SELECT id, sku, name, description_ai FROM products WHERE ai_status = 'draft' ORDER BY ai_generated_at DESC LIMIT 20"),
            'sync_log' => SyncLog::recent(12),
        ];
    }
}
