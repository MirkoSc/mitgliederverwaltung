<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Every route carries its access declaration (App\Http\Zugriff) as a
 * required argument - there is no way to register one without
 * (docs/spec/02-sicherheit.md section 6). Taken over from Vereinsbelege.
 *
 * Routes are not registered here directly but through App\Http\Routen,
 * which wraps each handler in the check its declaration asks for; the
 * route files under app/routes/ only ever see that registrar.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    public function get(string $pattern, Zugriff $zugriff, \Closure $handler): void
    {
        $this->add(HttpMethod::Get, $pattern, $zugriff, $handler);
    }

    public function post(string $pattern, Zugriff $zugriff, \Closure $handler): void
    {
        $this->add(HttpMethod::Post, $pattern, $zugriff, $handler);
    }

    public function add(HttpMethod $method, string $pattern, Zugriff $zugriff, \Closure $handler): void
    {
        $this->routes[] = new Route($method, $pattern, $zugriff, $handler);
    }

    /**
     * All registered routes with their declarations - for the access matrix
     * test (tests/Http/RoutenZugriffTest.php).
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /** Whether a route answers this method and path - for the navigation. */
    public function hat(HttpMethod $method, string $path): bool
    {
        return $this->match($method, $path)->type === MatchType::Matched;
    }

    public function match(HttpMethod $method, string $path): RouteMatch
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route->compile(), $path, $matches) !== 1) {
                continue;
            }
            if ($route->method !== $method) {
                if (!in_array($route->method, $allowed, true)) {
                    $allowed[] = $route->method;
                }
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            return RouteMatch::matched($route->handler, $params);
        }

        return $allowed === []
            ? RouteMatch::notFound()
            : RouteMatch::methodNotAllowed($allowed);
    }
}
