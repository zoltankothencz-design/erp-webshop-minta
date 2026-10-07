<?php
declare(strict_types=1);

namespace App;

/** A .env fájlból olvassa a beállításokat. A titkok (API-kulcsok, jelszavak) ide kerülnek, soha nem a kódba. */
final class Config
{
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$key, $val] = array_pad(explode('=', $line, 2), 2, '');
            self::$values[trim($key)] = trim($val);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }

    /** Tesztekhez: felülírja egy beállítás értékét. */
    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }
}
