<?php
declare(strict_types=1);

/**
 * Saját, függőségmentes tesztfuttató. Használat: .\scripts\dev.ps1 test
 * Minden teszt friss, üres tesztadatbázisból indul (webshop_minta_test), és külső hálózatot nem használ:
 * a HTTP-hívásokat hamis "transport" váltja ki, így a tesztek gyorsak és determinisztikusak.
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Db;
use App\Integration\ErpClient;
use App\Integration\GoogleAdsClient;
use App\Integration\HttpClient;
use App\Integration\IntegrationException;
use App\Integration\AiClient;
use App\Integration\MarketplaceClient;
use App\Migrator;
use App\Repository\ProductRepository;
use App\Service\AdsService;
use App\Service\ErpSyncService;
use App\Service\FeedExporter;
use App\Service\MarketplaceService;
use App\Service\OrderService;
use App\Service\ProductDescriptionService;
use App\ValidationException;

Config::set('DB_NAME', 'webshop_minta_test');
Config::set('APP_URL', 'http://shop.test');

// ------------------------------------------------------------------ mini teszt-keretrendszer
$tests = [];
function test(string $name, callable $fn): void { global $tests; $tests[$name] = $fn; }
function ok(bool $cond, string $msg = 'feltétel nem teljesült'): void { if (!$cond) { throw new AssertionError($msg); } }
function same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(($msg !== '' ? "$msg: " : '') . 'várt ' . var_export($expected, true) . ', kapott ' . var_export($actual, true));
    }
}
function throws(string $class, callable $fn): Throwable
{
    try { $fn(); } catch (Throwable $e) { if ($e instanceof $class) { return $e; } throw new AssertionError("$class helyett " . $e::class . ': ' . $e->getMessage()); }
    throw new AssertionError("$class kivételt vártunk, de nem dobódott");
}

/** HttpClient hamis transporttal. $handler(method, url, headers, body) => [status, tömb|string]. */
function fakeHttp(callable $handler, array &$calls = [], array &$sleeps = [], int $attempts = 3): HttpClient
{
    $transport = function (string $m, string $u, array $h, ?string $b, int $t) use ($handler, &$calls): array {
        $calls[] = ['method' => $m, 'url' => $u, 'headers' => $h, 'body' => $b];
        [$status, $body] = $handler($m, $u, $h, $b);
        return ['status' => $status, 'body' => is_string($body) ? $body : json_encode($body)];
    };
    return new HttpClient(5, $attempts, $transport, function (int $ms) use (&$sleeps): void { $sleeps[] = $ms; });
}

function seedProduct(string $sku, int $price, int $stock, bool $active = true, string $name = ''): int
{
    $catId = (int) Db::value("SELECT id FROM categories WHERE slug = 'teszt'");
    if ($catId === 0) {
        $catId = Db::insert("INSERT INTO categories (name, slug) VALUES ('Teszt', 'teszt')");
    }
    $name = $name !== '' ? $name : "Termék $sku";
    return Db::insert(
        'INSERT INTO products (erp_id, sku, name, slug, category_id, brand, ean, price, erp_stock, stock, active, description) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        ["E-$sku", $sku, $name, slugify("$name-$sku"), $catId, 'Márka', '5990000000011', $price, $stock, $stock, $active ? 1 : 0, 'Rövid leírás.']
    );
}

function customer(array $extra = []): array
{
    return $extra + ['customer_name' => 'Teszt Elek', 'email' => 'teszt@example.com', 'phone' => '+36201234567', 'address' => '1051 Budapest, Teszt utca 1.'];
}

function insertOrder(string $no, ?string $gclid, int $total, string $status = 'new'): int
{
    return Db::insert(
        "INSERT INTO orders (order_no, source, customer_name, email, phone, address, total, status, gclid) VALUES (?, 'web', 'T', 't@e.hu', '123456789', 'cim cim cim', ?, ?, ?)",
        [$no, $total, $status, $gclid]
    );
}

// ------------------------------------------------------------------ HttpClient
test('http: átmeneti hiba (503) után újrapróbál és sikerül, növekvő várakozással', function () {
    $n = 0; $calls = []; $sleeps = [];
    $http = fakeHttp(function () use (&$n) { return ++$n < 3 ? [503, 'busy'] : [200, ['ok' => true]]; }, $calls, $sleeps);
    $res = $http->request('GET', 'http://x.test/a');
    same(3, $res['attempts']);
    same([200, 400], $sleeps, 'várakozások');
});

test('http: feladja a próbálkozások után, és retryable hibát jelez', function () {
    $calls = []; $sleeps = [];
    $http = fakeHttp(fn() => [500, 'down'], $calls, $sleeps);
    $e = throws(IntegrationException::class, fn() => $http->request('GET', 'http://x.test/a'));
    same(3, count($calls));
    ok($e->retryable && $e->status === 500);
});

test('http: végleges hibát (400/401/404) nem próbál újra', function () {
    $calls = [];
    $http = fakeHttp(fn() => [401, 'no'], $calls);
    $e = throws(IntegrationException::class, fn() => $http->request('GET', 'http://x.test/a'));
    same(1, count($calls));
    ok(!$e->retryable);
});

test('http: hálózati hibát (kivétel a transportban) újrapróbál', function () {
    $n = 0;
    $http = new HttpClient(5, 3, function () use (&$n): array {
        if (++$n < 2) { throw new RuntimeException('connection reset'); }
        return ['status' => 200, 'body' => '{}'];
    }, fn() => null);
    same(2, $http->request('GET', 'http://x.test')['attempts']);
});

test('http: a hibaüzenetbe nem kerül titok a query-ből', function () {
    $http = fakeHttp(fn() => [404, 'nincs']);
    $e = throws(IntegrationException::class, fn() => $http->request('GET', 'http://x.test/a', ['query' => ['api_key' => 'TITKOS123']]));
    ok(!str_contains($e->getMessage(), 'TITKOS123'), 'kiszivárgott a titok');
});

// ------------------------------------------------------------------ Rendelés
test('rendelés: az ár az adatbázisból jön, a kliens által küldött ár nem számít', function () {
    seedProduct('A', 1000, 10);
    $r = (new OrderService())->createWebOrder(customer(['items' => [['sku' => 'A', 'qty' => 3, 'price' => 1]]]));
    same(3000, $r['total']);
    same(7, (int) Db::value("SELECT stock FROM products WHERE sku = 'A'"), 'készlet levonva');
});

test('rendelés: kevés készletnél elutasít, és semmi nem változik', function () {
    seedProduct('A', 1000, 2);
    throws(ValidationException::class, fn() => (new OrderService())->createWebOrder(customer(['items' => [['sku' => 'A', 'qty' => 3]]])));
    same(2, (int) Db::value("SELECT stock FROM products WHERE sku = 'A'"));
    same(0, (int) Db::value('SELECT COUNT(*) FROM orders'));
});

test('rendelés: ha a 2. tétel hibás, az 1. sem vonódik le (tranzakció visszagörgetés)', function () {
    seedProduct('A', 1000, 10);
    seedProduct('B', 500, 1);
    throws(ValidationException::class, fn() => (new OrderService())->createWebOrder(customer(['items' => [['sku' => 'A', 'qty' => 2], ['sku' => 'B', 'qty' => 5]]])));
    same(10, (int) Db::value("SELECT stock FROM products WHERE sku = 'A'"));
    same(0, (int) Db::value('SELECT COUNT(*) FROM order_items'));
});

test('rendelés: inaktív és ismeretlen terméket nem fogad el', function () {
    seedProduct('OFF', 1000, 10, false);
    foreach (['OFF', 'NINCS'] as $sku) {
        throws(ValidationException::class, fn() => (new OrderService())->createWebOrder(customer(['items' => [['sku' => $sku, 'qty' => 1]]])));
    }
});

test('rendelés: érvénytelen mennyiségek (0, -1, 21, szöveg) elutasítva', function () {
    seedProduct('A', 1000, 100);
    foreach ([0, -1, 21, 'abc', 1.5] as $qty) {
        throws(ValidationException::class, fn() => (new OrderService())->createWebOrder(customer(['items' => [['sku' => 'A', 'qty' => $qty]]])), "qty=$qty");
    }
});

test('rendelés: a hibás elérhetőségi adatok mezőnként jelennek meg', function () {
    seedProduct('A', 1000, 5);
    $e = throws(ValidationException::class, fn() => (new OrderService())->createWebOrder(['customer_name' => 'X', 'email' => 'nem-email', 'phone' => 'abc', 'address' => '', 'items' => [['sku' => 'A', 'qty' => 1]]]));
    ok(isset($e->errors['customer_name'], $e->errors['email'], $e->errors['phone'], $e->errors['address']));
});

test('rendelés: érvénytelen gclid/utm nem kerül az adatbázisba, az érvényes igen', function () {
    seedProduct('A', 1000, 5);
    (new OrderService())->createWebOrder(customer(['items' => [['sku' => 'A', 'qty' => 1]], 'attribution' => ['gclid' => "x'; DROP TABLE orders;--", 'utm_source' => 'google']]));
    $o = Db::one('SELECT gclid, utm_source FROM orders');
    same(null, $o['gclid']);
    same('google', $o['utm_source']);
    (new OrderService())->createWebOrder(customer(['items' => [['sku' => 'A', 'qty' => 1]], 'attribution' => ['gclid' => 'Cj0KCQjw_VALID_click_1']]));
    same('Cj0KCQjw_VALID_click_1', Db::value("SELECT gclid FROM orders WHERE gclid IS NOT NULL"));
});

test('keresés: SQL-injekció és LIKE-joker nem működik', function () {
    seedProduct('A', 1000, 5, true, 'Sima termék');
    $repo = new ProductRepository();
    same(0, count($repo->search(null, "' OR 1=1 -- ")), 'injekció');
    same(0, count($repo->search(null, '%')), 'a % nem joker');
    same(1, count($repo->search(null, 'Sima')));
    same(1, (int) Db::value('SELECT COUNT(*) FROM products'), 'a tábla érintetlen');
});

// ------------------------------------------------------------------ ERP
test('ERP szinkron: beszúr, frissít, és a még el nem küldött rendelések foglalását levonja a készletből', function () {
    $erpStock = 10; $calls = [];
    $http = fakeHttp(function ($m, $u) use (&$erpStock) {
        return [200, ['data' => [['erp_id' => 'E1', 'sku' => 'A', 'name' => 'Alfa', 'category' => 'Rádió', 'brand' => 'V', 'ean' => '5990000000011', 'price' => 1000, 'stock' => $erpStock, 'description' => 'x', 'active' => true, 'updated_at' => '2026-10-01 10:00:00']], 'page' => 1, 'total_pages' => 1]];
    }, $calls);
    $sync = new ErpSyncService(new ErpClient($http, 'http://erp.test', 'k'));
    $sync->syncProducts();
    same(10, (int) Db::value("SELECT stock FROM products WHERE sku = 'A'"));

    (new OrderService())->createWebOrder(customer(['items' => [['sku' => 'A', 'qty' => 3]]]));   // az ERP erről még nem tud
    $sync->syncProducts();                                                                         // az ERP még 10-et mond
    $row = Db::one("SELECT erp_stock, stock FROM products WHERE sku = 'A'");
    same([10, 7], [(int) $row['erp_stock'], (int) $row['stock']], 'foglalás levonva');
    same('2026-10-01 10:00:00', SyncLogState('erp_products_since'), 'kurzor az adatból');
});

function SyncLogState(string $k): ?string { return App\SyncLog::getState($k); }

test('ERP rendelés-küldés: az order_no az idempotencia-kulcs; hibás rendelés nem állítja meg a többit', function () {
    seedProduct('A', 1000, 20);
    $svc = new OrderService();
    $r1 = $svc->createWebOrder(customer(['items' => [['sku' => 'A', 'qty' => 1]]]));
    $r2 = $svc->createWebOrder(customer(['items' => [['sku' => 'A', 'qty' => 2]]]));
    $calls = [];
    $http = fakeHttp(function ($m, $u, $h, $b) use ($r1) {
        $payload = json_decode((string) $b, true);
        return $payload['order_no'] === $r1['order_no'] ? [422, 'elutasítva'] : [201, ['erp_order_id' => 'ERP-9']];
    }, $calls);
    $res = (new ErpSyncService(new ErpClient($http, 'http://erp.test', 'k')))->pushOrders();
    same(1, $res['items']);
    same('new', Db::value('SELECT status FROM orders WHERE order_no = ?', [$r1['order_no']]), 'a hibás rendelés marad new');
    same('sent_to_erp', Db::value('SELECT status FROM orders WHERE order_no = ?', [$r2['order_no']]));
    $keys = array_map(fn($c) => $c['headers']['Idempotency-Key'] ?? null, $calls);
    ok(in_array($r2['order_no'], $keys, true), 'Idempotency-Key = order_no');
});

// ------------------------------------------------------------------ Marketplace
function mpOrder(string $ref, string $sku, int $qty, int $price, string $at): array
{
    return ['marketplace_ref' => $ref, 'created_at' => $at, 'buyer' => ['name' => 'Vevő Vera', 'email' => 'v@example.com', 'phone' => '+36201112222', 'address' => 'Budapest, Próba u. 1'], 'items' => [['sku' => $sku, 'qty' => $qty, 'unit_price' => $price]]];
}

test('marketplace import: duplikátumot nem importál újra, a készletet levonja', function () {
    seedProduct('A', 1000, 10);
    $orders = [mpOrder('MP-1', 'A', 2, 900, '2026-10-01 10:00:00')];
    $http = fakeHttp(fn() => [200, ['orders' => $orders]]);
    $svc = new MarketplaceService(new MarketplaceClient($http, 'http://mp.test', 'k'));
    same(1, $svc->importOrders()['items']);
    same(0, $svc->importOrders()['items'], 'másodszorra nem új');
    same(1, (int) Db::value('SELECT COUNT(*) FROM orders'));
    same(1800, (int) Db::value('SELECT total FROM orders'), 'a marketplace ára az irányadó');
    same(8, (int) Db::value("SELECT stock FROM products WHERE sku = 'A'"));
});

test('marketplace import: ismeretlen cikkszámnál megáll, a kurzor nem lép tovább, és hibát jelez', function () {
    seedProduct('A', 1000, 10);
    $orders = [mpOrder('MP-1', 'A', 1, 900, '2026-10-01 10:00:00'), mpOrder('MP-2', 'NINCS', 1, 900, '2026-10-01 11:00:00'), mpOrder('MP-3', 'A', 1, 900, '2026-10-01 12:00:00')];
    $svc = new MarketplaceService(new MarketplaceClient(fakeHttp(fn() => [200, ['orders' => $orders]]), 'http://mp.test', 'k'));
    throws(IntegrationException::class, fn() => $svc->importOrders());
    same(1, (int) Db::value('SELECT COUNT(*) FROM orders'), 'csak az 1. importálódott, a 3. nem előz meg egy hibás 2.-at');
    same('2026-10-01 10:00:00', SyncLogState('mp_orders_since'));
});

test('marketplace ajánlat-push: csak a változott termékeket küldi', function () {
    seedProduct('A', 1000, 10);
    $calls = [];
    $svc = new MarketplaceService(new MarketplaceClient(fakeHttp(fn() => [200, ['status' => 'accepted']], $calls), 'http://mp.test', 'k'));
    same(1, $svc->pushOffers()['items']);
    same(0, $svc->pushOffers()['items'], 'nincs változás, nincs hívás');
    same(1, count($calls));
    same(['price' => 1000, 'stock' => 10], json_decode((string) $calls[0]['body'], true));
});

// ------------------------------------------------------------------ Feed
test('feed: érvényes XML, helyes ár- és készletformátum, speciális karakterek kiescape-elve', function () {
    seedProduct('IN', 12990, 5, true, 'Kábel & csatlakozó <teszt>');
    seedProduct('OUT', 500, 0, true, 'Kifogyott');
    $xml = simplexml_load_string((new FeedExporter())->googleMerchant());
    ok($xml !== false, 'érvényes XML');
    $items = $xml->channel->item;
    same(2, count($items));
    $g = $items[0]->children('http://base.google.com/ns/1.0');
    same('12990 HUF', (string) $g->price);
    same('in_stock', (string) $g->availability);
    same('Kábel & csatlakozó <teszt>', (string) $g->title, 'a & és < megmaradt szövegként');
    same('out_of_stock', (string) $items[1]->children('http://base.google.com/ns/1.0')->availability);
});

// ------------------------------------------------------------------ Google Ads
function adsClient(callable $handler, array &$calls = []): GoogleAdsClient
{
    return new GoogleAdsClient(fakeHttp($handler, $calls), [
        'base_url' => 'http://ads.test/v17', 'token_url' => 'http://ads.test/token', 'customer_id' => '1234567890', 'login_customer_id' => '',
        'developer_token' => 'dev', 'client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'r', 'conversion_action_id' => '42',
    ]);
}

test('Ads statisztika: mikro-egységből forintra vált, és újrafuttatva nem duplikál', function () {
    $handler = function ($m, $u) {
        if (str_contains($u, '/token')) { return [200, ['access_token' => 'T', 'expires_in' => 3600]]; }
        return [200, ['results' => [['campaign' => ['id' => '7', 'name' => 'Teszt kampány'], 'segments' => ['date' => date('Y-m-d')],
            'metrics' => ['impressions' => '1000', 'clicks' => '50', 'costMicros' => '12345000000', 'conversions' => 2.5, 'conversionsValue' => 90000.0]]]]];
    };
    $svc = new AdsService(adsClient($handler));
    $svc->syncStats();
    $svc->syncStats();
    $row = Db::one('SELECT cost, clicks, conv_value FROM campaign_stats');
    same([12345, 50, 90000], [(int) $row['cost'], (int) $row['clicks'], (int) $row['conv_value']]);
    same(1, (int) Db::value('SELECT COUNT(*) FROM campaign_stats'), 'nincs duplikátum');
});

test('Ads: érvénytelen dátum (GAQL-injekció) hívás nélkül elutasítva', function () {
    $calls = [];
    $client = adsClient(fn() => [200, []], $calls);
    throws(InvalidArgumentException::class, fn() => $client->campaignStats("2026-01-01' OR 1=1 --", '2026-01-02'));
    same(0, count($calls), 'nem ment ki kérés');
});

test('Ads konverzió: csak gclid-es rendelést tölt fel, egyszer, a részleges hibát külön kezeli', function () {
    insertOrder('GOOD-1', 'Cj0KCQjw_GOODCLICK_1', 45000);
    insertOrder('BAD-1', 'Cj0KCQjw_BADCLICK_01', 9000);
    insertOrder('NOCLICK', null, 7000);
    insertOrder('CANCELLED', 'Cj0KCQjw_CANCELLED_1', 100, 'cancelled');
    $uploads = 0; $calls = [];
    $handler = function ($m, $u, $h, $b) use (&$uploads) {
        if (str_contains($u, '/token')) { return [200, ['access_token' => 'T', 'expires_in' => 3600]]; }
        $uploads++;
        $results = []; $err = [];
        foreach (json_decode((string) $b, true)['conversions'] as $c) {
            if ($c['orderId'] === 'BAD-1') { $results[] = new stdClass(); $err[] = 'BAD-1 elutasítva'; }
            else { $results[] = ['gclid' => $c['gclid']]; }
        }
        return [200, ['results' => $results] + ($err ? ['partialFailureError' => ['message' => implode(';', $err)]] : [])];
    };
    $svc = new AdsService(adsClient($handler, $calls));
    $res = $svc->uploadConversions();
    same(1, $res['items'], 'csak a GOOD-1 sikerült');
    same('uploaded', Db::value("SELECT u.status FROM conversion_uploads u JOIN orders o ON o.id = u.order_id WHERE o.order_no = 'GOOD-1'"));
    same('failed', Db::value("SELECT u.status FROM conversion_uploads u JOIN orders o ON o.id = u.order_id WHERE o.order_no = 'BAD-1'"));
    same(2, (int) Db::value('SELECT COUNT(*) FROM conversion_uploads'), 'a gclid nélküli és a törölt rendelés ki van hagyva');
    $svc->uploadConversions();
    same(1, $uploads, 'másodszorra nincs új feltöltés');
    $upload = array_values(array_filter($calls, fn($c) => str_contains($c['url'], 'uploadClickConversions')))[0];
    $conv = json_decode((string) $upload['body'], true)['conversions'][0];
    same('customers/1234567890/conversionActions/42', $conv['conversionAction']);
    same('HUF', $conv['currencyCode']);
    ok(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $conv['conversionDateTime']) === 1, 'időformátum: ' . $conv['conversionDateTime']);
});

// ------------------------------------------------------------------ AI
test('AI leírás: piszkozat, jóváhagyásig nem látszik a boltban; a kimenet megtisztítva', function () {
    $id = seedProduct('A', 1000, 5, true, 'Teszt rádió');
    $seen = [];
    $ai = new class($seen) implements AiClient {
        public function __construct(public array &$seen) {}
        public function complete(string $system, string $user, int $maxTokens = 400): string
        {
            $this->seen = ['system' => $system, 'user' => $user];
            return "<b>Jó</b> leírás.\n<script>x()</script>";
        }
    };
    $svc = new ProductDescriptionService($ai);
    $text = $svc->generateFor($id);
    ok(!str_contains($text, '<'), 'nincs HTML a kimenetben');
    ok(str_contains($seen['user'], '<termek_adatok>') && str_contains($seen['user'], 'Név: Teszt rádió'), 'az adatok címkézve mennek be');
    ok(str_contains($seen['system'], 'Ne találj ki'), 'a prompt tiltja a kitalálást');

    $repo = new ProductRepository();
    $pub = $repo->toPublic($repo->findBySlug(Db::value('SELECT slug FROM products WHERE id = ?', [$id])));
    same('Rövid leírás.', $pub['description'], 'jóváhagyás előtt az ERP-leírás látszik');
    ok($svc->review($id, true));
    ok(!$svc->review($id, false), 'jóváhagyott leírás már nem módosítható véletlenül');
    $pub = $repo->toPublic($repo->findBySlug(Db::value('SELECT slug FROM products WHERE id = ?', [$id])));
    ok(str_contains($pub['description'], 'Jó leírás'), 'jóváhagyás után az AI-leírás látszik');
});

test('segéd: slugify ékezeteket és speciális karaktereket kezel', function () {
    same('kezi-urh-adovevo-5-w', slugify('Kézi URH adóvevő 5 W'));
    same('n-a', slugify('!!!'));
});

// ------------------------------------------------------------------ futtatás
$pass = 0;
$fail = 0;
foreach ($tests as $name => $fn) {
    Migrator::fresh();
    try {
        $fn();
        $pass++;
        echo "  ok    $name\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  HIBA  $name\n        " . $e->getMessage() . "\n";
    }
}
echo "\n$pass sikeres, $fail hibás, " . count($tests) . " teszt.\n";
exit($fail === 0 ? 0 : 1);
