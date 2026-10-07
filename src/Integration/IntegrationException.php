<?php
declare(strict_types=1);

namespace App\Integration;

/** Külső rendszer hívása sikertelen. A $status a HTTP-kód (0 = hálózati hiba), a $retryable jelzi, érdemes-e újrapróbálni. */
final class IntegrationException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
