<?php
declare(strict_types=1);

namespace App;

/** Az adatbázis-séma verziózása: database/migrations/*.sql fájlokat futtat sorrendben, és megjegyzi, melyik futott le. */
final class Migrator
{
    /** @return list<string> a most lefuttatott migrációk nevei */
    public static function run(): array
    {
        $dbName = Config::get('DB_NAME', 'webshop_minta');
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $dbName)) {
            throw new \RuntimeException('Érvénytelen adatbázisnév.');
        }
        self::createDatabase($dbName);
        Db::reset();

        Db::run('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $done = array_column(Db::all('SELECT name FROM schema_migrations'), 'name');

        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            Db::pdo()->exec((string) file_get_contents($file));
            Db::run('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Tesztekhez: eldobja és újra létrehozza az adatbázist. */
    public static function fresh(): void
    {
        $dbName = (string) Config::get('DB_NAME');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName) || !str_ends_with($dbName, '_test')) {
            throw new \RuntimeException('A fresh() csak *_test nevű adatbázison futhat.');
        }
        self::connectNoDb()->exec("DROP DATABASE IF EXISTS `$dbName`");
        Db::reset();
        self::run();
    }

    private static function connectNoDb(): \PDO
    {
        return Db::connect(null);
    }

    private static function createDatabase(string $dbName): void
    {
        self::connectNoDb()->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}
