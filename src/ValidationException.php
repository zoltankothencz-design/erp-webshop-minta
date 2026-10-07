<?php
declare(strict_types=1);

namespace App;

/** Hibás felhasználói bemenet. Az API ezt 422-es válaszként adja vissza a mezőnkénti hibákkal. */
final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Érvénytelen adatok: ' . implode('; ', $errors));
    }
}
