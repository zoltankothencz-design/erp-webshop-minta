<?php
declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\ValidationException;

/**
 * Rendelés felvétele. A legfontosabb szabály: a böngészőből jövő adatnak nem hiszünk.
 * Az árat, a nevet és a készletet mindig az adatbázisból vesszük, a kliens csak azt mondja meg, mit és mennyit szeretne.
 */
final class OrderService
{
    private const MAX_QTY_PER_LINE = 20;
    private const MAX_LINES = 30;

    /** @return array{order_no:string,total:int} */
    public function createWebOrder(array $in): array
    {
        $errors = [];
        $name = $this->clean($in['customer_name'] ?? '', 150);
        if (mb_strlen($name) < 3) {
            $errors['customer_name'] = 'Kérjük, adja meg a teljes nevét.';
        }
        $email = trim((string) ($in['email'] ?? ''));
        if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Érvénytelen e-mail cím.';
        }
        $phone = $this->clean($in['phone'] ?? '', 40);
        if (preg_match('/^[0-9+()\/ -]{7,40}$/', $phone) !== 1) {
            $errors['phone'] = 'Érvénytelen telefonszám.';
        }
        $address = $this->clean($in['address'] ?? '', 255);
        if (mb_strlen($address) < 8) {
            $errors['address'] = 'Kérjük, adja meg a teljes szállítási címet.';
        }
        $lines = $this->normalizeLines($in['items'] ?? null, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $attr = $this->attribution($in['attribution'] ?? []);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // FOR UPDATE: amíg ez a tranzakció tart, más rendelés nem módosíthatja ezeket a sorokat.
            // Id szerint rendezve zárolunk, hogy két párhuzamos rendelés ne akadjon össze (deadlock).
            $skus = array_keys($lines);
            $rows = Db::all(
                'SELECT id, sku, name, price, stock, active FROM products WHERE sku IN (' . Db::placeholders($skus) . ') ORDER BY id FOR UPDATE',
                $skus
            );
            $bySku = array_column($rows, null, 'sku');

            $total = 0;
            foreach ($lines as $sku => $qty) {
                $p = $bySku[$sku] ?? null;
                if ($p === null || (int) $p['active'] !== 1) {
                    $errors['items'] = "A(z) $sku termék nem rendelhető.";
                    break;
                }
                if ((int) $p['stock'] < $qty) {
                    $errors['items'] = sprintf('A(z) "%s" termékből jelenleg legfeljebb %d db rendelhető.', $p['name'], (int) $p['stock']);
                    break;
                }
                $total += (int) $p['price'] * $qty;   // az ár az adatbázisból jön
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }

            $orderNo = 'W' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $orderId = Db::insert(
                'INSERT INTO orders (order_no, source, customer_name, email, phone, address, total, status, utm_source, utm_medium, utm_campaign, gclid)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$orderNo, 'web', $name, $email, $phone, $address, $total, 'new', $attr['utm_source'], $attr['utm_medium'], $attr['utm_campaign'], $attr['gclid']]
            );
            foreach ($lines as $sku => $qty) {
                $p = $bySku[$sku];
                Db::run(
                    'INSERT INTO order_items (order_id, product_id, sku, name, unit_price, qty) VALUES (?, ?, ?, ?, ?, ?)',
                    [$orderId, $p['id'], $p['sku'], $p['name'], $p['price'], $qty]
                );
                Db::run('UPDATE products SET stock = stock - ? WHERE id = ?', [$qty, $p['id']]);
            }
            $pdo->commit();
            return ['order_no' => $orderNo, 'total' => $total];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();   // hiba esetén semmi nem marad félig: se rendelés, se levont készlet
            }
            throw $e;
        }
    }

    /**
     * @param array<string,string> $errors
     * @return array<string,int> sku => darabszám (azonos termék többszöri szereplése összevonva)
     */
    private function normalizeLines(mixed $items, array &$errors): array
    {
        if (!is_array($items) || $items === []) {
            $errors['items'] = 'A kosár üres.';
            return [];
        }
        if (count($items) > self::MAX_LINES) {
            $errors['items'] = 'Túl sok tétel egy rendelésben.';
            return [];
        }
        $lines = [];
        foreach ($items as $item) {
            $sku = is_array($item) ? ($item['sku'] ?? null) : null;
            $qty = is_array($item) ? ($item['qty'] ?? null) : null;
            if (!is_string($sku) || $sku === '' || mb_strlen($sku) > 60 || filter_var($qty, FILTER_VALIDATE_INT) === false) {
                $errors['items'] = 'Érvénytelen tétel a kosárban.';
                return [];
            }
            $lines[$sku] = ($lines[$sku] ?? 0) + (int) $qty;
            if ($lines[$sku] < 1 || $lines[$sku] > self::MAX_QTY_PER_LINE) {
                $errors['items'] = 'Egy termékből 1 és ' . self::MAX_QTY_PER_LINE . ' db közötti mennyiség rendelhető.';
                return [];
            }
        }
        return $lines;
    }

    /** Csak ismert formátumú marketing-azonosítót fogadunk el; minden más null lesz. */
    private function attribution(mixed $a): array
    {
        $a = is_array($a) ? $a : [];
        $utm = static fn(string $k): ?string => preg_match('/^[\w .\-+|%]{1,100}$/u', (string) ($a[$k] ?? '')) === 1 ? (string) $a[$k] : null;
        $gclid = preg_match('/^[A-Za-z0-9_-]{10,200}$/', (string) ($a['gclid'] ?? '')) === 1 ? (string) $a['gclid'] : null;
        return ['utm_source' => $utm('utm_source'), 'utm_medium' => $utm('utm_medium'), 'utm_campaign' => $utm('utm_campaign'), 'gclid' => $gclid];
    }

    private function clean(mixed $value, int $max): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $value)) ?? '';
        return mb_substr($s, 0, $max);
    }
}
