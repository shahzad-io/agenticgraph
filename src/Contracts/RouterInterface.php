<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

interface RouterInterface
{
    public function route(StateContract $state): string|array;

    public function getAvailableRoutes(): array;

    public function getDefaultRoute(): ?string;

    public function validateRoute(string $route): bool;
}
