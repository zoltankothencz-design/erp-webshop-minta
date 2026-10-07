<?php
declare(strict_types=1);

namespace App;

use PDO;

/** Vékony PDO-burkoló. Minden lekérdezés paraméterezett (prepared statement), így nincs SQL-injekció. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(Config::get('DB_NAME', 'webshop_minta'));
        }
        return self::$pdo;
    }

    /** Új kapcsolat; $dbName=null esetén adatbázis nélkül (létrehozáshoz). */
    public static function connect(?string $dbName): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;%scharset=utf8mb4',
            Config::get('DB_HOST', '127.0.0.1'),
            Config::get('DB_PORT', '3306'),
            $dbName !== null ? 'dbname=' . $dbName . ';' : ''
        );
        return new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Érintett sorok száma. */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** "?, ?, ?" az IN (...) feltételhez. */
    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
