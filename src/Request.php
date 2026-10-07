<?php
declare(strict_types=1);

namespace App;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        private readonly array $server,
        private readonly string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_SERVER,
            file_get_contents('php://input') ?: ''
        );
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    /** A kérés törzse JSON-ként (üres tömb, ha nem érvényes). */
    public function json(): array
    {
        $data = json_decode($this->rawBody, true);
        return is_array($data) ? $data : [];
    }

    public function q(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }
}
