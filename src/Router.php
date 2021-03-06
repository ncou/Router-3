<?php

namespace Rareloop\Router;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rareloop\Router\Exceptions\NamedRouteNotFoundException;
use Rareloop\Router\Exceptions\TooLateToAddNewRouteException;
use Rareloop\Router\Helpers\Formatting;
use Rareloop\Router\Invoker;
use Rareloop\Router\Routable;
use Rareloop\Router\Route;
use Rareloop\Router\RouteGroup;
use Rareloop\Router\RouteParams;
use Rareloop\Router\VerbShortcutsTrait;
use Zend\Diactoros\Response\TextResponse;
use \AltoRouter;
use mindplay\middleman\Dispatcher;

class Router implements Routable
{
    use VerbShortcutsTrait;

    private $routes = [];
    private $altoRouter;
    private $altoRoutesCreated = false;
    private $basePath;

    private $container = null;
    private $invoker = null;
    private $baseMiddleware = [];

    public function __construct(ContainerInterface $container = null)
    {
        if (isset($container)) {
            $this->setContainer($container);
        }

        $this->setBasePath('/');
    }

    private function setContainer(ContainerInterface $container)
    {
        $this->container = $container;

        // Create an invoker for this container. This allows us to use the `call()` method even if
        // the container doesn't support it natively
        $this->invoker = new Invoker($this->container);
    }

    public function setBasePath($basePath)
    {
        $this->basePath = Formatting::addLeadingSlash(Formatting::addTrailingSlash($basePath));

        // Force the router to rebuild next time we need it
        $this->altoRoutesCreated = false;
    }

    private function addRoute(Route $route)
    {
        if ($this->altoRoutesCreated) {
            throw new TooLateToAddNewRouteException();
        }

        $this->routes[] = $route;
    }

    private function convertUriForAltoRouter(string $uri): string
    {
        return ltrim(preg_replace('/{\s*([a-zA-Z0-9]+)\s*}/s', '[:$1]', $uri), ' /');
    }

    public function map(array $verbs, string $uri, $callback): Route
    {
        // Force all verbs to be uppercase
        $verbs = array_map('strtoupper', $verbs);

        $route = new Route($verbs, $uri, $callback, $this->invoker);

        $this->addRoute($route);

        return $route;
    }

    private function createAltoRoutes()
    {
        if ($this->altoRoutesCreated) {
            return;
        }

        $this->altoRouter = new AltoRouter();
        $this->altoRouter->setBasePath($this->basePath);
        $this->altoRoutesCreated = true;

        foreach ($this->routes as $route) {
            $uri = $this->convertUriForAltoRouter($route->getUri());

            // Canonical URI with trailing slash - becomes named route if name is provided
            $this->altoRouter->map(
                implode('|', $route->getMethods()),
                Formatting::addTrailingSlash($uri),
                $route,
                $route->getName() ?? null
            );

            // Also register URI without trailing slash
            $this->altoRouter->map(
                implode('|', $route->getMethods()),
                Formatting::removeTrailingSlash($uri),
                $route
            );
        }
    }

    public function match(ServerRequestInterface $request)
    {
        $this->createAltoRoutes();

        $altoRoute = $this->altoRouter->match($request->getUri()->getPath(), $request->getMethod());

        $route = $altoRoute['target'];
        $params = new RouteParams($altoRoute['params'] ?? []);

        if (!$route) {
            return new TextResponse('Resource not found', 404);
        }

        return $this->handle($route, $request, $params);
    }

    protected function handle($route, $request, $params)
    {
        if (count($this->baseMiddleware) === 0) {
            return $route->handle($request, $params);
        }

        // Apply all the base middleware and trigger the route handler as the last in the chain
        $middlewares = array_merge($this->baseMiddleware, [
            function ($request) use ($route, $params) {
                return $route->handle($request, $params);
            },
        ]);

        // Create and process the dispatcher
        $dispatcher = new Dispatcher($middlewares);
        return $dispatcher->dispatch($request);
    }

    public function has(string $name)
    {
        $routes = array_filter($this->routes, function ($route) use ($name) {
            return $route->getName() === $name;
        });

        return count($routes) > 0;
    }

    public function url(string $name, $params = [])
    {
        $this->createAltoRoutes();

        try {
            return $this->altoRouter->generate($name, $params);
        } catch (\Exception $e) {
            throw new NamedRouteNotFoundException($name, null);
        }
    }

    public function group($params, $callback) : Router
    {
        $group = new RouteGroup($params, $this);

        call_user_func($callback, $group);

        return $this;
    }

    public function setBaseMiddleware(array $middleware)
    {
        $this->baseMiddleware = $middleware;
    }
}
