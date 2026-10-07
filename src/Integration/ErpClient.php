<?php
declare(strict_types=1);

namespace App\Integration;

/** Az ERP REST API kliense. Csak a kommunikációt tudja; hogy mit kezdünk az adattal, az a Service réteg dolga. */
final class ErpClient
{
    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
        private string $apiKey,
    ) {
    }

    /**
     * Termékek, oldalanként lekérve. A $updatedSince-től módosultakat adja (inkrementális szinkron).
     * @return \Generator<int, array>
     */
    public function products(?string $updatedSince = null): \Generator
    {
        $page = 1;
        do {
            $query = ['page' => $page, 'per_page' => 50];
            if ($updatedSince !== null) {
                $query['updated_since'] = $updatedSince;
            }
            $res = $this->http->request('GET', $this->baseUrl . '/products', ['headers' => $this->headers(), 'query' => $query]);
            $json = $res['json'];
            if (!is_array($json) || !isset($json['data'])) {
                throw new IntegrationException('Az ERP válasza nem a várt formátumú.');
            }
            foreach ($json['data'] as $product) {
                yield $product;
            }
            $page++;
        } while ($page <= (int) ($json['total_pages'] ?? 1));
    }

    /**
     * Rendelés beküldése. Az Idempotency-Key miatt biztonságos újra elküldeni: az ERP nem hoz létre duplikátumot.
     * @return array{erp_order_id:string}
     */
    public function createOrder(array $payload, string $idempotencyKey): array
    {
        $res = $this->http->request('POST', $this->baseUrl . '/orders', [
            'headers' => $this->headers() + ['Idempotency-Key' => $idempotencyKey],
            'json' => $payload,
        ]);
        if (!isset($res['json']['erp_order_id'])) {
            throw new IntegrationException('Az ERP nem adott vissza rendelésazonosítót.');
        }
        return ['erp_order_id' => (string) $res['json']['erp_order_id']];
    }

    private function headers(): array
    {
        return ['X-Api-Key' => $this->apiKey];
    }
}
