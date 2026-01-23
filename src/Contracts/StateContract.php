<?php

namespace Shahzad\AgenticGraph\Contracts;

interface StateContract
{
    public function toArray(): array;

    public static function fromArray(array $data): static;

    public function merge(StateContract $other): static;

    public function validate(): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): static;

    public function has(string $key): bool;

    public function forget(string $key): static;

    public function all(): array;

    public function getMetadata(): array;
}
