<?php
declare(strict_types=1);

namespace Walkie\Core;

/**
 * Minimal method+path router with {param} placeholders.
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, params:string[], handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function get(string $p, callable $h): void    { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void   { $this->add('POST', $p, $h); }
    public function patch(string $p, callable $h): void  { $this->add('PATCH', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    /**
     * @return array{0:callable,1:array<string,string>}
     * @throws ApiException 404 / 405
     */
    public function match(string $method, string $path): array
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            array_shift($m);
            $params = array_combine($route['params'], $m) ?: [];
            return [$route['handler'], $params];
        }
        if ($pathMatched) {
            throw new ApiException(405, 'method_not_allowed', 'Method not allowed');
        }
        throw ApiException::notFound('Unknown endpoint');
    }
}
