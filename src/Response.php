<?php
declare(strict_types=1);

namespace App;

final class Response
{
    private function __construct(
        private int $status,
        private string $body,
        private array $headers,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function xml(string $xml): self
    {
        return new self(200, $xml, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public static function redirect(string $to): self
    {
        return new self(302, '', ['Location' => $to]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        // Alap biztonsági fejlécek minden válaszon
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
