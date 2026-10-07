<?php
declare(strict_types=1);

/**
 * SZIMULÁLT KÜLSŐ RENDSZEREK (ERP, marketplace, Google Ads) egyetlen kis szerveren, a 8081-es porton.
 * Célja: kulcsok és élő fiókok nélkül is kipróbálhatóak legyenek az integrációk.
 * A válaszok formáját a valódi API-k mintájára írtam; élesben csak az URL és a hitelesítő adat változik.
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Request;
use App\Response;
use App\Router;

const STATE_FILE = APP_ROOT . '/storage/mock/state.json';

function mock_state(): array
{
    if (!is_file(STATE_FILE)) {
        $s = [
            'products' => require __DIR__ . '/data/products.php',
            'erp_orders' => [], 'idempotency' => [], 'mp_orders' => [], 'offers' => [],
            'conversions' => [], 'fail_next' => 0,
        ];
        mock_save($s);
        return $s;
    }
    return json_decode((string) file_get_contents(STATE_FILE), true);
}

function mock_save(array $s): void
{
    file_put_contents(STATE_FILE, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function need_key(Request $r, string $expected): ?Response
{
    return hash_equals($expected, (string) $r->header('X-Api-Key')) ? null : Response::json(['error' => 'invalid api key'], 401);
}

$router = new Router();

// ---------------------------------------------------------------- ERP
$router->get('/erp/products', function (Request $r): Response {
    if ($deny = need_key($r, 'demo-erp-key')) {
        return $deny;
    }
    $since = $r->q('updated_since');
    $items = array_values(array_filter(mock_state()['products'], fn($p) => !$since || $p['updated_at'] >= $since));
    $per = max(1, min(100, (int) $r->q('per_page', '10')));
    $page = max(1, (int) $r->q('page', '1'));
    return Response::json([
        'data' => array_slice($items, ($page - 1) * $per, $per),
        'page' => $page, 'total' => count($items), 'total_pages' => max(1, (int) ceil(count($items) / $per)),
    ]);
});

$router->post('/erp/orders', function (Request $r): Response {
    if ($deny = need_key($r, 'demo-erp-key')) {
        return $deny;
    }
    $body = $r->json();
    $key = (string) $r->header('Idempotency-Key');
    if ($key === '' || empty($body['order_no']) || empty($body['items'])) {
        return Response::json(['error' => 'order_no, items és Idempotency-Key kötelező'], 422);
    }
    $s = mock_state();
    if (isset($s['idempotency'][$key])) {   // ugyanaz a kérés újra: ugyanazt a választ kapja, nem jön létre második rendelés
        return Response::json(['erp_order_id' => $s['idempotency'][$key], 'duplicate' => true], 200);
    }
    $id = 'ERP-' . str_pad((string) (count($s['erp_orders']) + 1), 6, '0', STR_PAD_LEFT);
    foreach ($body['items'] as $it) {
        foreach ($s['products'] as &$p) {
            if ($p['sku'] === ($it['sku'] ?? '')) {
                $p['stock'] = max(0, $p['stock'] - (int) $it['qty']);
                $p['updated_at'] = date('Y-m-d H:i:s');
            }
        }
        unset($p);
    }
    $s['erp_orders'][$id] = $body;
    $s['idempotency'][$key] = $id;
    mock_save($s);
    return Response::json(['erp_order_id' => $id], 201);
});

// ---------------------------------------------------------------- Marketplace
$router->get('/marketplace/orders', function (Request $r): Response {
    if ($deny = need_key($r, 'demo-marketplace-key')) {
        return $deny;
    }
    $since = $r->q('since');
    $orders = array_values(array_filter(mock_state()['mp_orders'], fn($o) => !$since || $o['created_at'] > $since));
    return Response::json(['orders' => $orders]);
});

$router->put('/marketplace/offers/{sku}', function (Request $r, array $p): Response {
    if ($deny = need_key($r, 'demo-marketplace-key')) {
        return $deny;
    }
    $body = $r->json();
    if (!isset($body['price'], $body['stock'])) {
        return Response::json(['error' => 'price és stock kötelező'], 422);
    }
    $s = mock_state();
    $s['offers'][$p['sku']] = ['price' => (int) $body['price'], 'stock' => (int) $body['stock'], 'at' => date('c')];
    mock_save($s);
    return Response::json(['sku' => $p['sku'], 'status' => 'accepted']);
});

// ---------------------------------------------------------------- Google Ads (a valódi REST API formája szerint)
$router->post('/ads/oauth/token', function (Request $r): Response {
    $in = $r->post;
    if (($in['grant_type'] ?? '') !== 'refresh_token' || ($in['refresh_token'] ?? '') !== 'demo-refresh-token') {
        return Response::json(['error' => 'invalid_grant'], 400);
    }
    return Response::json(['access_token' => 'mock-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
});

function ads_denied(Request $r): ?Response
{
    if ($r->header('Authorization') !== 'Bearer mock-access-token' || $r->header('developer-token') !== 'demo-dev-token') {
        return Response::json(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401);
    }
    return null;
}

$router->post('/ads/v17/customers/{cid}/googleAds:search', function (Request $r): Response {
    if ($deny = ads_denied($r)) {
        return $deny;
    }
    $query = (string) ($r->json()['query'] ?? '');
    if (!preg_match("/BETWEEN '(\d{4}-\d{2}-\d{2})' AND '(\d{4}-\d{2}-\d{2})'/", $query, $m)) {
        return Response::json(['error' => ['code' => 400, 'message' => 'Hiányzó dátumszűrő']], 400);
    }
    $campaigns = ['2001' => ['Márka - keresés', 140], '2002' => ['Shopping - rádiók', 220], '2003' => ['Remarketing', 90]];
    $results = [];
    for ($t = strtotime($m[1]), $end = min(strtotime($m[2]), strtotime($m[1]) + 120 * 86400); $t <= $end; $t += 86400) {
        $date = date('Y-m-d', $t);
        foreach ($campaigns as $id => [$name, $cpc]) {
            mt_srand(crc32($id . $date));
            $imp = mt_rand(800, 3000);
            $clicks = (int) ($imp * mt_rand(20, 60) / 1000);
            $conv = round($clicks * mt_rand(15, 60) / 1000, 1);
            $results[] = [
                'campaign' => ['id' => $id, 'name' => $name],
                'segments' => ['date' => $date],
                // A Google REST API az egész számokat stringként adja, a költséget mikro-egységben (1 Ft = 1 000 000)
                'metrics' => [
                    'impressions' => (string) $imp, 'clicks' => (string) $clicks,
                    'costMicros' => (string) ($clicks * $cpc * 1000000),
                    'conversions' => $conv, 'conversionsValue' => $conv * 36000.0,
                ],
            ];
        }
    }
    return Response::json(['results' => $results]);
});

$router->post('/ads/v17/customers/{cid}:uploadClickConversions', function (Request $r): Response {
    if ($deny = ads_denied($r)) {
        return $deny;
    }
    $s = mock_state();
    $results = [];
    $errors = [];
    foreach (($r->json()['conversions'] ?? []) as $i => $c) {
        $orderId = (string) ($c['orderId'] ?? '');
        if (empty($c['gclid']) || empty($c['conversionAction']) || empty($c['conversionDateTime'])) {
            $results[] = new stdClass();
            $errors[] = "conversions[$i]: kötelező mező hiányzik";
        } elseif ($orderId !== '' && isset($s['conversions'][$orderId])) {
            $results[] = new stdClass();
            $errors[] = "conversions[$i]: DUPLICATE_ORDER_ID ($orderId)";
        } else {
            $s['conversions'][$orderId !== '' ? $orderId : uniqid()] = $c;
            $results[] = ['gclid' => $c['gclid'], 'conversionAction' => $c['conversionAction'], 'conversionDateTime' => $c['conversionDateTime']];
        }
    }
    mock_save($s);
    $out = ['results' => $results];
    if ($errors) {
        $out['partialFailureError'] = ['code' => 3, 'message' => implode('; ', $errors)];
    }
    return Response::json($out);
});

// ---------------------------------------------------------------- Vezérlő végpontok a demóhoz (_ előtaggal)
$router->post('/_simulate/erp', function (): Response {
    $s = mock_state();
    $keys = array_rand($s['products'], 4);
    $changed = [];
    foreach ($keys as $k) {
        $p = &$s['products'][$k];
        $p['stock'] = max(0, $p['stock'] + random_int(-3, 15));
        $p['price'] = (int) (round($p['price'] * (random_int(92, 108) / 100) / 100) * 100);
        $p['updated_at'] = date('Y-m-d H:i:s');
        $changed[] = $p['sku'];
        unset($p);
    }
    mock_save($s);
    return Response::json(['changed' => $changed]);
});

$router->post('/_simulate/marketplace', function (): Response {
    $s = mock_state();
    $inStock = array_values(array_filter($s['products'], fn($p) => $p['stock'] > 2 && $p['active']));
    $names = ['Kiss Péter', 'Nagy Anna', 'Szabó Gábor', 'Tóth Eszter', 'Horváth Zoltán'];
    $created = [];
    for ($i = 0; $i < 2; $i++) {
        $p = $inStock[array_rand($inStock)];
        $qty = random_int(1, 2);
        $ref = 'MP-' . (1001 + count($s['mp_orders']));
        $s['mp_orders'][] = [
            'marketplace_ref' => $ref, 'created_at' => date('Y-m-d H:i:s'),
            'buyer' => ['name' => $names[array_rand($names)], 'email' => 'vevo' . random_int(100, 999) . '@example.com', 'phone' => '+3620' . random_int(1000000, 9999999), 'address' => '1051 Budapest, Teszt utca ' . random_int(1, 40)],
            'items' => [['sku' => $p['sku'], 'qty' => $qty, 'unit_price' => $p['price']]],
        ];
        $created[] = $ref;
    }
    mock_save($s);
    return Response::json(['created' => $created]);
});

$router->post('/_fail', function (Request $r): Response {
    $s = mock_state();
    $s['fail_next'] = max(0, (int) $r->q('n', '2'));
    mock_save($s);
    return Response::json(['fail_next' => $s['fail_next']]);
});

$router->post('/_reset', function (): Response {
    @unlink(STATE_FILE);
    return Response::json(['reset' => true]);
});

$router->get('/_state', function (): Response {
    $s = mock_state();
    return Response::json(['products' => count($s['products']), 'erp_orders' => count($s['erp_orders']), 'mp_orders' => count($s['mp_orders']),
        'offers' => count($s['offers']), 'conversions' => count($s['conversions']), 'fail_next' => $s['fail_next']]);
});

// Hibaszimuláció: ha a "_fail" számláló nem nulla, a következő valódi kérések 503-at kapnak (az újrapróbálkozás teszteléséhez)
$request = Request::fromGlobals();
if (!str_starts_with($request->path, '/_')) {
    $s = mock_state();
    if ($s['fail_next'] > 0) {
        $s['fail_next']--;
        mock_save($s);
        Response::json(['error' => 'szimulált hiba'], 503)->send();
        return true;
    }
}
$router->dispatch($request)->send();
