<?php
declare(strict_types=1);

namespace App;

/** Útvonal -> függvény. A {param} részek a handler második argumentumába kerülnek. */
final class Router
{
    /** @var list<array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback('#\{(\w+)\}|([^{]+)#', function (array $m): string {
            return isset($m[2]) ? preg_quote($m[2], '#') : '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler];
    }

    public function dispatch(Request $request): Response
    {
        $methodsForPath = [];
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (preg_match($regex, $request->path, $m) !== 1) {
                continue;
            }
            if ($method !== $request->method) {
                $methodsForPath[] = $method;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($request, array_map('urldecode', $params));
        }
        if ($methodsForPath !== []) {
            return Response::json(['error' => 'Nem engedélyezett metódus'], 405);
        }
        return $this->notFound($request);
    }

    private function notFound(Request $request): Response
    {
        $isApi = str_starts_with($request->path, '/api') || str_starts_with($request->path, '/admin/api');
        if ($isApi) {
            return Response::json(['error' => 'Nincs ilyen végpont'], 404);
        }
        return Response::html(View::page('404', [], 'Nem található'), 404);
    }
}
