<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Routeur minimal : « GET /content/{id} » => callable.
 * {id} = nombre, {slug} = segment, {any} = reste du chemin.
 */
final class Router
{
    /** @var list<array{0: string, 1: string, 2: callable|array}> */
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function any(string $pattern, callable|array $handler): self
    {
        return $this->add('ANY', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable|array $handler): self
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static fn ($m) => match ($m[1]) {
            'id' => '(?P<id>\d+)',
            'any' => '(?P<any>.*)',
            default => '(?P<' . $m[1] . '>[^/]+)',
        }, $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#u', $handler];
        return $this;
    }

    /** Renvoie [handler, params] ou null. Un HEAD est traité comme un GET. */
    public function match(string $method, string $path): ?array
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = false;
        foreach ($this->routes as [$m, $regex, $handler]) {
            if (preg_match($regex, $path, $mm)) {
                if ($m !== 'ANY' && $m !== $method) {
                    $allowed = true;
                    continue;
                }
                $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
                return [$handler, $params];
            }
        }
        return $allowed ? ['405', []] : null;
    }

    public static function call(callable|array $handler, array $params): mixed
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }
        return $handler(...array_values($params));
    }
}
