<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Nodes;

use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

abstract class AbstractNode implements NodeInterface
{
    public function getName(): string
    {
        // Allow nodes to override with a property
        if (property_exists($this, 'name') && $this->name) {
            return $this->name;
        }

        // Otherwise, generate readable name from class name
        $className = class_basename(static::class);

        // Remove "Node" suffix if present
        $className = preg_replace('/Node$/', '', $className);

        // Convert PascalCase to Title Case
        return preg_replace('/(?<!^)[A-Z]/', ' $0', $className);
    }

    public function getDescription(): ?string
    {
        return null;
    }

    public function getTimeout(): ?int
    {
        return 300;
    }

    public function getRetryAttempts(): int
    {
        return 3;
    }

    public function getRetryDelay(): int
    {
        return 1000;
    }

    public function shouldRetry(\Throwable $exception): bool
    {
        return true;
    }

    public function getTags(): array
    {
        return [];
    }

    public function onBefore(StateContract $state): void {}

    public function onAfter(StateContract $state, StateContract $result): void {}

    public function onError(\Throwable $exception, StateContract $state): void {}
}
