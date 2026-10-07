<?php
declare(strict_types=1);

/** HTML-escape. Minden kimenetet ezen át írunk ki (XSS elleni védelem). */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(int $n): string
{
    return number_format($n, 0, ',', ' ') . ' Ft';
}

/** URL-barát név: "Kézi URH adóvevő" -> "kezi-urh-adovevo" */
function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s !== '' ? $s : 'n-a';
}

/** Ugyanaz, mint a kozepso `?? ` de üres stringre is a defaultot adja. */
function env_or(string $key, string $default = ''): string
{
    $v = App\Config::get($key);
    return ($v === null || $v === '') ? $default : $v;
}
