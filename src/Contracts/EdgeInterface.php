<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

interface EdgeInterface
{
    public function getSource(): string;

    public function getTarget(): string|array;

    public function getCondition(): ?RouterInterface;

    public function shouldTraverse(StateContract $state): bool;

    public function getMetadata(): array;
}
