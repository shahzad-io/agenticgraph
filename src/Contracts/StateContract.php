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
}
