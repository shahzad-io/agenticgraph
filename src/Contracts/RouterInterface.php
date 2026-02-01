<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

interface RouterInterface
{
    /**
     * @return string|array<int, string>
     */
    public function route(StateContract $state): string|array;

    /**
     * @return array<int, string>
     */
    public function getAvailableRoutes(): array;

    public function getDefaultRoute(): ?string;

    public function validateRoute(string $route): bool;
}
