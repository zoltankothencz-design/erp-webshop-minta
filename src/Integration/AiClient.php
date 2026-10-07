<?php
declare(strict_types=1);

namespace App\Integration;

/** Egy szöveggeneráló AI szolgáltatás. A kód csak ezt az interfészt ismeri, így a szolgáltató cserélhető, és tesztben kiváltható. */
interface AiClient
{
    public function complete(string $system, string $user, int $maxTokens = 400): string;
}
