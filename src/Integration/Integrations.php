<?php
declare(strict_types=1);

namespace App\Integration;

/** Összerakja a klienseket a .env beállításaiból. Itt dől el, hogy a mock vagy az éles rendszert hívjuk. */
final class Integrations
{
    public static function http(int $timeout = 10): HttpClient
    {
        return new HttpClient(timeout: $timeout, maxAttempts: 3);
    }

    public static function erp(): ErpClient
    {
        return new ErpClient(self::http(), env_or('ERP_BASE_URL'), env_or('ERP_API_KEY'));
    }

    public static function marketplace(): MarketplaceClient
    {
        return new MarketplaceClient(self::http(), env_or('MARKETPLACE_BASE_URL'), env_or('MARKETPLACE_API_KEY'));
    }

    public static function ads(): GoogleAdsClient
    {
        return new GoogleAdsClient(self::http(), [
            'base_url' => env_or('ADS_BASE_URL'),
            'token_url' => env_or('ADS_OAUTH_TOKEN_URL'),
            'customer_id' => env_or('ADS_CUSTOMER_ID'),
            'login_customer_id' => env_or('ADS_LOGIN_CUSTOMER_ID'),
            'developer_token' => env_or('ADS_DEVELOPER_TOKEN'),
            'client_id' => env_or('ADS_CLIENT_ID'),
            'client_secret' => env_or('ADS_CLIENT_SECRET'),
            'refresh_token' => env_or('ADS_REFRESH_TOKEN'),
            'conversion_action_id' => env_or('ADS_CONVERSION_ACTION_ID'),
        ]);
    }

    public static function ai(): AiClient
    {
        return match (env_or('AI_DRIVER', 'mock')) {
            'claude' => new ClaudeClient(self::http(60), env_or('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), env_or('ANTHROPIC_API_KEY'), env_or('AI_MODEL', 'claude-sonnet-5-5')),
            'mock' => new MockAiClient(),
            default => throw new \RuntimeException('Ismeretlen AI_DRIVER (mock vagy claude lehet).'),
        };
    }
}
