<?php

namespace Shahzad\AgenticGraph\Routers;

use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

abstract class AbstractRouter implements RouterInterface
{
    protected array $routes = [];
    protected ?string $defaultRoute = null;

    public function route(StateContract $state): string|array
    {
        throw new \BadMethodCallException(
            'Router ' . static::class . ' must implement route() method'
        );
    }

    public function getAvailableRoutes(): array
    {
        return array_keys($this->routes);
    }

    public function getDefaultRoute(): ?string
    {
        return $this->defaultRoute;
    }

    public function validateRoute(string $route): bool
    {
        return in_array($route, $this->getAvailableRoutes(), true);
    }
}
