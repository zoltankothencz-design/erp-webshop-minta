<?php
declare(strict_types=1);

namespace App;

/** Az integrációs futások naplója (a sync_log tábla). Hibakereséshez ez az első hely, ahová nézni kell. */
final class SyncLog
{
    public static function start(string $job): int
    {
        return Db::insert('INSERT INTO sync_log (job, status, started_at) VALUES (?, ?, NOW())', [$job, 'running']);
    }

    public static function finish(int $id, string $status, string $message, int $items = 0): void
    {
        Db::run(
            'UPDATE sync_log SET status = ?, message = ?, items = ?, finished_at = NOW() WHERE id = ?',
            [$status, mb_substr($message, 0, 500), $items, $id]
        );
    }

    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return Db::all("SELECT id, job, status, message, items, started_at, finished_at FROM sync_log ORDER BY id DESC LIMIT $limit");
    }

    public static function getState(string $key): ?string
    {
        $v = Db::value('SELECT v FROM sync_state WHERE k = ?', [$key]);
        return $v === null ? null : (string) $v;
    }

    public static function setState(string $key, string $value): void
    {
        Db::run('INSERT INTO sync_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $value]);
    }
}
