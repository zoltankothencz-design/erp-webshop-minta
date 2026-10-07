<?php
declare(strict_types=1);

namespace App\Integration;

/**
 * Google Ads REST API kliens, két feladatra:
 *   1. kampány-teljesítmény lekérése (GAQL lekérdezés a googleAds:search végponton)
 *   2. offline konverzió feltöltése (uploadClickConversions): a webshop rendelését visszaküldjük a hirdetésnek a gclid-del
 *
 * FIGYELEM: a végpontok és mezőnevek a nyilvános REST dokumentáció alapján készültek, és a helyi szimulált szerverrel
 * vannak tesztelve. Éles fiókkal még nem futottak. Élesítés előtt ellenőrizni kell az aktuális API-verziót,
 * a developer tokent, a conversion action azonosítót és a hozzájárulási (consent) követelményeket.
 */
final class GoogleAdsClient
{
    private ?string $token = null;
    private int $tokenExpires = 0;

    /** @param array{base_url:string,token_url:string,customer_id:string,login_customer_id:string,developer_token:string,client_id:string,client_secret:string,refresh_token:string,conversion_action_id:string} $cfg */
    public function __construct(private HttpClient $http, private array $cfg)
    {
        if (preg_match('/^\d{6,12}$/', $cfg['customer_id']) !== 1) {
            throw new \InvalidArgumentException('Az ADS_CUSTOMER_ID csak számjegyekből állhat (kötőjel nélkül).');
        }
    }

    /**
     * @return list<array{campaign_id:string,campaign_name:string,date:string,impressions:int,clicks:int,cost:int,conversions:float,conv_value:int}>
     */
    public function campaignStats(string $from, string $to): array
    {
        // A dátum bekerül a GAQL szövegbe, ezért szigorúan ellenőrizzük (a GAQL-nek nincs paraméterezett formája).
        foreach ([$from, $to] as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
                throw new \InvalidArgumentException('Érvénytelen dátum: ' . $d);
            }
        }
        $query = "SELECT campaign.id, campaign.name, segments.date, metrics.impressions, metrics.clicks, metrics.cost_micros, "
            . "metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '$from' AND '$to'";

        $rows = [];
        $pageToken = null;
        do {
            $body = ['query' => $query] + ($pageToken !== null ? ['pageToken' => $pageToken] : []);
            $res = $this->http->request('POST', $this->url('/googleAds:search'), ['headers' => $this->headers(), 'json' => $body]);
            foreach (($res['json']['results'] ?? []) as $r) {
                $m = $r['metrics'] ?? [];
                $rows[] = [
                    'campaign_id' => (string) ($r['campaign']['id'] ?? ''),
                    'campaign_name' => (string) ($r['campaign']['name'] ?? ''),
                    'date' => (string) ($r['segments']['date'] ?? ''),
                    'impressions' => (int) ($m['impressions'] ?? 0),
                    'clicks' => (int) ($m['clicks'] ?? 0),
                    // A költséget "mikro" egységben adja a Google: 1 000 000 mikro = 1 pénzegység
                    'cost' => (int) round(((int) ($m['costMicros'] ?? 0)) / 1_000_000),
                    'conversions' => (float) ($m['conversions'] ?? 0),
                    'conv_value' => (int) round((float) ($m['conversionsValue'] ?? 0)),
                ];
            }
            $pageToken = $res['json']['nextPageToken'] ?? null;
        } while ($pageToken);
        return $rows;
    }

    /**
     * @param list<array{gclid:string,order_ref:string,time:string,value:int,currency:string}> $conversions
     * @return list<array{ok:bool,message:string}> a bemenettel azonos sorrendben
     */
    public function uploadClickConversions(array $conversions): array
    {
        if ($conversions === []) {
            return [];
        }
        $payload = array_map(fn($c) => [
            'gclid' => $c['gclid'],
            'conversionAction' => 'customers/' . $this->cfg['customer_id'] . '/conversionActions/' . $this->cfg['conversion_action_id'],
            'conversionDateTime' => $c['time'],            // formátum: "2026-10-06 12:32:45+02:00"
            'conversionValue' => $c['value'],
            'currencyCode' => $c['currency'],
            'orderId' => $c['order_ref'],                  // a Google ez alapján szűri ki a duplikátumot
        ], $conversions);

        // partialFailure: ha egy elem hibás, a többit a Google attól még feldolgozza
        $res = $this->http->request('POST', $this->url(':uploadClickConversions'), [
            'headers' => $this->headers(),
            'json' => ['conversions' => $payload, 'partialFailure' => true],
        ]);
        $results = $res['json']['results'] ?? [];
        $error = $res['json']['partialFailureError']['message'] ?? '';
        $out = [];
        foreach ($conversions as $i => $_) {
            $ok = isset($results[$i]) && is_array($results[$i]) && !empty($results[$i]['gclid']);
            $out[] = ['ok' => $ok, 'message' => $ok ? 'feltöltve' : ($error !== '' ? mb_substr($error, 0, 200) : 'a Google nem fogadta el')];
        }
        return $out;
    }

    private function url(string $suffix): string
    {
        return rtrim($this->cfg['base_url'], '/') . '/customers/' . $this->cfg['customer_id'] . $suffix;
    }

    private function headers(): array
    {
        $h = ['Authorization' => 'Bearer ' . $this->accessToken(), 'developer-token' => $this->cfg['developer_token']];
        if ($this->cfg['login_customer_id'] !== '') {
            $h['login-customer-id'] = $this->cfg['login_customer_id'];
        }
        return $h;
    }

    /** OAuth2: a tartós refresh tokenből rövid életű access tokent kérünk, és a lejáratig újrahasznosítjuk. */
    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpires - 60) {
            return $this->token;
        }
        $res = $this->http->request('POST', $this->cfg['token_url'], ['form' => [
            'client_id' => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'refresh_token' => $this->cfg['refresh_token'],
            'grant_type' => 'refresh_token',
        ]]);
        if (empty($res['json']['access_token'])) {
            throw new IntegrationException('Az OAuth token-kérés nem adott access tokent.');
        }
        $this->token = (string) $res['json']['access_token'];
        $this->tokenExpires = time() + (int) ($res['json']['expires_in'] ?? 3600);
        return $this->token;
    }
}
