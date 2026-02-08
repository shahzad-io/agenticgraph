<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

interface RouterInterface
{
    /**
     * Determine the next node(s) based on the current state.
     *
     * @param  StateContract  $state  Current execution state
     * @return string|array<int, string> Node name(s) to route to
     */
    public function route(StateContract $state): string|array;

    /**
     * Get all available route names.
     *
     * @return array<int, string> List of valid node names this router can return
     */
    public function getAvailableRoutes(): array;

    /**
     * Get the default route when routing fails or is ambiguous.
     *
     * @return string|null Default node name or null if no default
     */
    public function getDefaultRoute(): ?string;

    /**
     * Validate if a route name is valid.
     *
     * @param  string  $route  Route name to validate
     * @return bool True if the route is valid
     */
    public function validateRoute(string $route): bool;
}
