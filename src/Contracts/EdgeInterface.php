<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

interface EdgeInterface
{
    public function getSource(): string;

    /**
     * @return string|array<int, string>
     */
    public function getTarget(): string|array;

    public function getCondition(): ?RouterInterface;

    public function shouldTraverse(StateContract $state): bool;

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array;
}
