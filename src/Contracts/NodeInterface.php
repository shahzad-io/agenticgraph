<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

interface NodeInterface
{
    public function execute(StateContract $state): StateContract;

    public function getName(): string;

    public function getDescription(): ?string;

    public function getTimeout(): ?int;

    public function getRetryAttempts(): int;

    public function getRetryDelay(): int;

    public function shouldRetry(\Throwable $exception): bool;

    /**
     * @return array<int, string>
     */
    public function getTags(): array;

    public function onBefore(StateContract $state): void;

    public function onAfter(StateContract $state, StateContract $result): void;

    public function onError(\Throwable $exception, StateContract $state): void;
}
