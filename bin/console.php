<?php
declare(strict_types=1);

/**
 * Parancssori belépési pont. Használat:
 *   php bin/console.php migrate          adatbázis-séma létrehozása/frissítése
 *   php bin/console.php seed             admin felhasználó létrehozása
 *   php bin/console.php jobs             integrációs feladatok listája
 *   php bin/console.php job <név>        egy integrációs feladat futtatása (ezt hívná a cron is)
 *   php bin/console.php simulate <mit>   a szimulált külső rendszerben változást idéz elő (erp|marketplace|fail)
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Db;
use App\Migrator;

$cmd = $argv[1] ?? 'help';

switch ($cmd) {
    case 'migrate':
        $applied = Migrator::run();
        echo $applied === [] ? "Nincs új migráció.\n" : 'Lefuttatva: ' . implode(', ', $applied) . "\n";
        break;

    case 'seed':
        $user = env_or('ADMIN_USER', 'admin');
        $pass = Config::get('ADMIN_PASSWORD');
        if ($pass === null || $pass === '') {
            fwrite(STDERR, "Hiányzik az ADMIN_PASSWORD a .env fájlból.\n");
            exit(1);
        }
        Db::run(
            'INSERT INTO admin_users (username, password_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)',
            [$user, password_hash($pass, PASSWORD_DEFAULT)]
        );
        echo "Admin felhasználó létrehozva/frissítve: $user\n";
        break;

    case 'jobs':
        foreach (App\Service\JobRunner::names() as $name) {
            echo $name . "\n";
        }
        break;

    case 'job':
        $name = $argv[2] ?? '';
        $result = App\Service\JobRunner::run($name);
        echo sprintf("[%s] %s (%d elem)\n", $result['status'], $result['message'], $result['items']);
        exit($result['status'] === 'ok' ? 0 : 1);

    case 'simulate':
        $what = $argv[2] ?? '';
        $map = ['erp' => '/_simulate/erp', 'marketplace' => '/_simulate/marketplace', 'fail' => '/_fail?n=' . (int) ($argv[3] ?? 2)];
        if (!isset($map[$what])) {
            fwrite(STDERR, "Használat: simulate erp|marketplace|fail [n]\n");
            exit(1);
        }
        $base = preg_replace('#/(erp|marketplace|ads.*)$#', '', env_or('ERP_BASE_URL', 'http://127.0.0.1:8081/erp'));
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "X-Api-Key: demo-erp-key\r\nContent-Length: 0\r\n", 'ignore_errors' => true, 'timeout' => 5]]);
        echo file_get_contents($base . $map[$what], false, $ctx) . "\n";
        break;

    default:
        echo "Parancsok: migrate | seed | jobs | job <név> | simulate <erp|marketplace|fail>\n";
}
