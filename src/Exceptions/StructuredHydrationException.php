<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use Prism\Prism\Exceptions\PrismException;

/**
 * A structured reply that is valid JSON but misses a key its schema requires, or does not fit the DTO given to as().
 * The message names the path (items.2.price), and for as() the class, the expected type and what was received; it is
 * also what the structured retry feeds back.
 */
final class StructuredHydrationException extends PrismException
{
    public static function at(string $class, string $path, string $expected, mixed $received, bool $missing = false): self
    {
        $where = $path === '' ? $class : "{$class} at [{$path}]";

        return new self($missing
            ? "Cannot hydrate {$where}: the required value is missing (expected {$expected})."
            : "Cannot hydrate {$where}: expected {$expected}, got ".self::describe($received).'.');
    }

    public static function missingKey(string $path): self
    {
        return new self("The structured reply does not match the schema: the required key [{$path}] is missing.");
    }

    public static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_string($value) => 'string "'.mb_strimwidth($value, 0, 40, '...').'"',
            is_bool($value) => 'bool '.($value ? 'true' : 'false'),
            is_int($value), is_float($value) => get_debug_type($value).' '.$value,
            is_array($value) => array_is_list($value) ? 'a list' : 'an object',
            default => get_debug_type($value),
        };
    }
}
