<?php
declare(strict_types=1);

namespace App\Integration;

/** Összerakja a klienseket a .env beállításaiból. Itt dől el, hogy a mock vagy az éles rendszert hívjuk. */
final class Integrations
{
    public static function http(): HttpClient
    {
        return new HttpClient(timeout: 10, maxAttempts: 3);
    }

    public static function erp(): ErpClient
    {
        return new ErpClient(self::http(), env_or('ERP_BASE_URL'), env_or('ERP_API_KEY'));
    }
}
