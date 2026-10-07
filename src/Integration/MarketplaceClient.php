<?php
declare(strict_types=1);

namespace App\Integration;

/** Egy marketplace API kliense: rendelések lehúzása és ár/készlet (ajánlat) frissítése. */
final class MarketplaceClient
{
    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
        private string $apiKey,
    ) {
    }

    /** @return list<array> a $since időpont óta érkezett rendelések */
    public function orders(?string $since): array
    {
        $query = $since !== null ? ['since' => $since] : [];
        $res = $this->http->request('GET', $this->baseUrl . '/orders', ['headers' => $this->headers(), 'query' => $query]);
        if (!isset($res['json']['orders']) || !is_array($res['json']['orders'])) {
            throw new IntegrationException('A marketplace válasza nem a várt formátumú.');
        }
        return $res['json']['orders'];
    }

    public function updateOffer(string $sku, int $price, int $stock): void
    {
        $this->http->request('PUT', $this->baseUrl . '/offers/' . rawurlencode($sku), [
            'headers' => $this->headers(),
            'json' => ['price' => $price, 'stock' => $stock],
        ]);
    }

    private function headers(): array
    {
        return ['X-Api-Key' => $this->apiKey];
    }
}
