<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\NotFoundException;

class Router
{
    private Request $request;
    private array $routes = [];
    private array $globalMiddleware = [];
    private array $groupStack = [];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function addGlobalMiddleware(string $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function get(string $path, string $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, string $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, string $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, string $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    private function addRoute(string $method, string $path, string $handler): void
    {
        $prefix = $this->getGroupPrefix();
        $middleware = $this->getGroupMiddleware();

        $this->routes[] = [
            'method' => $method,
            'path' => $prefix . $path,
            'handler' => $handler,
            'middleware' => $middleware
        ];
    }

    private function getGroupPrefix(): string
    {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            if (isset($group['prefix'])) {
                $prefix .= $group['prefix'];
            }
        }
        return $prefix;
    }

    private function getGroupMiddleware(): array
    {
        $middleware = [];
        foreach ($this->groupStack as $group) {
            if (isset($group['middleware'])) {
                $middleware = array_merge($middleware, $group['middleware']);
            }
        }
        return $middleware;
    }

    public function dispatch(): void
    {
        $method = $this->request->getMethod();
        $path = $this->request->getPath();

        // Handle OPTIONS preflight requests
        if ($method === 'OPTIONS') {
            $this->handlePreflight();
            return;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $path);
            if ($params !== false) {
                $this->request->setParams($params);
                $this->executeRoute($route);
                return;
            }
        }

        throw new NotFoundException("Route not found: {$method} {$path}");
    }

    private function matchPath(string $routePath, string $requestPath): array|false
    {
        // Convert route pattern to regex
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $requestPath, $matches)) {
            // Extract only named parameters
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return false;
    }

    private function handlePreflight(): void
    {
        // Execute global middleware for CORS headers
        foreach ($this->globalMiddleware as $middlewareClass) {
            $middleware = new $middlewareClass();
            $middleware->handle($this->request);
        }

        Response::json(null, 204);
    }

    private function executeRoute(array $route): void
    {
        // Execute global middleware
        foreach ($this->globalMiddleware as $middlewareClass) {
            $middleware = new $middlewareClass();
            $middleware->handle($this->request);
        }

        // Execute route-specific middleware
        foreach ($route['middleware'] as $middlewareClass) {
            $middleware = new $middlewareClass();
            $middleware->handle($this->request);
        }

        // Parse handler
        [$controllerName, $methodName] = explode('@', $route['handler']);

        // Build full controller class name
        if (str_contains($controllerName, '\\')) {
            $controllerClass = 'App\\Controllers\\' . $controllerName;
        } else {
            $controllerClass = 'App\\Controllers\\' . $controllerName;
        }

        // Instantiate controller and call method
        $controller = new $controllerClass();
        $controller->$methodName($this->request);
    }
}
