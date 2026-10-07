<?php
declare(strict_types=1);

namespace App\Integration;

/** Kulcs és hálózat nélküli helyettesítő: a kapott adatokból sablon-szöveget állít össze. Fejlesztéshez és tesztekhez. */
final class MockAiClient implements AiClient
{
    public function complete(string $system, string $user, int $maxTokens = 400): string
    {
        $get = static fn(string $label): string => preg_match('/^' . preg_quote($label, '/') . ': (.*)$/mu', $user, $m) === 1 ? trim($m[1]) : '';
        $name = $get('Név');
        $brand = $get('Márka');
        $category = $get('Kategória');
        $spec = $get('Műszaki leírás');
        return trim("$name: $brand márkájú termék a(z) $category kategóriából. $spec (Mintaszöveg, nem valódi AI-kimenet.)");
    }
}
