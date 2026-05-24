<?php
/**
 * APEX - tiny path-pattern router.
 *
 * Usage:
 *   $router = new Router();
 *   $router->get('/api/projects',          fn() => listProjects());
 *   $router->get('/api/projects/{id}',     fn($p) => getProject($p['id']));
 *   $router->post('/api/tickets',          fn() => createTicket());
 *   $router->dispatch($method, $path);
 *
 * Patterns: literal segments + {placeholders}. The matched values are
 * passed to the handler as an associative array.
 */

declare(strict_types=1);

namespace Apex;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, params:string[], handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function ($m) use (&$params) {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );
        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'regex'   => '#^' . $regex . '/?$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /** Dispatch and exit. Returns 404 / 405 on no match. */
    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $matchedPathButWrongMethod = false;

        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            if ($r['method'] !== $method) {
                $matchedPathButWrongMethod = true;
                continue;
            }
            array_shift($m); // drop full match
            $args = [];
            foreach ($r['params'] as $i => $name) {
                $args[$name] = $m[$i] ?? null;
            }
            ($r['handler'])($args);
            return;
        }

        if ($matchedPathButWrongMethod) {
            Response::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
        }
        Response::notFound("No route for $method $path");
    }
}
