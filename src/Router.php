<?php

declare(strict_types=1);

namespace Panel;

final class Router
{
    /** @var array<string, list<array{segments: list<string>, handler: callable}>> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[strtoupper($method)][] = [
            'segments' => $this->segments($pattern),
            'handler' => $handler,
        ];
    }

    /** @return ?array{handler: callable, params: array<string, string>} */
    public function match(string $method, string $path): ?array
    {
        $pathSegments = $this->segments($path);

        foreach ($this->routes[strtoupper($method)] ?? [] as $route) {
            if (count($route['segments']) !== count($pathSegments)) {
                continue;
            }
            $params = [];
            foreach ($route['segments'] as $i => $segment) {
                if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                    $params[substr($segment, 1, -1)] = rawurldecode($pathSegments[$i]);
                } elseif ($segment !== $pathSegments[$i]) {
                    continue 2;
                }
            }

            return ['handler' => $route['handler'], 'params' => $params];
        }

        return null;
    }

    /** @return list<string> */
    private function segments(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), fn ($s) => $s !== ''));
    }
}
