<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Contracts;

interface StateContract
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static;

    public function merge(StateContract $other): static;

    public function validate(): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): static;

    public function has(string $key): bool;

    public function forget(string $key): static;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * Set interrupt metadata on the state.
     * Returns a new instance (immutable).
     */
    public function setInterrupt(string $reason, string $checkpointId): static;

    /**
     * Check if state has an interrupt.
     */
    public function hasInterrupt(): bool;

    /**
     * Get interrupt metadata.
     *
     * @return array<string, mixed>|null
     */
    public function getInterrupt(): ?array;

    /**
     * Get the checkpoint ID from the interrupt.
     */
    public function getInterruptCheckpointId(): ?string;

    /**
     * Clear interrupt metadata.
     * Returns a new instance (immutable).
     */
    public function clearInterrupt(): static;
}
