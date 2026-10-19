<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Llm;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Shahzad\AgenticGraph\Exceptions\StructuredHydrationException;
use Throwable;

/**
 * Builds a DTO from a decoded structured reply with reflection only. Through the constructor (named arguments), or
 * public properties when there is none. Strict: no "5" to int (an int is accepted for float). Extra keys are ignored;
 * a missing key takes the parameter default, then null if nullable, else it is an error naming the path.
 * Types: scalars, array, mixed, nullable and union, backed enums, date-times, nested DTOs, and lists of those
 * through #[ListOf] or a docblock `@param list<Item> $items`.
 */
final class DtoHydrator
{
    /**
     * @param  class-string<T>  $class
     * @param  array<mixed>  $data
     * @return T
     *
     * @template T of object
     */
    public static function hydrate(string $class, array $data): object
    {
        /** @var T */
        return self::object($class, $data, '');
    }

    /**
     * @param  class-string  $class
     */
    private static function object(string $class, mixed $data, string $path): object
    {
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw StructuredHydrationException::at($class, $path, 'an object', $data);
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            $instance = $reflection->newInstance();
            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $name = $property->getName();
                $at = self::join($path, $name);
                if (array_key_exists($name, $data)) {
                    $property->setValue($instance, self::value($property->getType(), $data[$name], $at, $class, self::listType($property->getAttributes(ListOf::class)[0] ?? null, null, $name, $class)));
                } elseif (! $property->isStatic() && ! $property->isInitialized($instance)) {
                    $property->getType()?->allowsNull() === true
                        ? $property->setValue($instance, null)
                        : throw StructuredHydrationException::at($class, $at, (string) $property->getType(), null, missing: true);
                }
            }

            return $instance;
        }

        $docblock = $constructor->getDocComment() ?: '';
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $at = self::join($path, $name);
            if (! array_key_exists($name, $data)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $arguments[$name] = null;

                    continue;
                }
                throw StructuredHydrationException::at($class, $at, (string) $parameter->getType(), null, missing: true);
            }
            $arguments[$name] = self::value($parameter->getType(), $data[$name], $at, $class, self::listType($parameter->getAttributes(ListOf::class)[0] ?? null, $docblock, $name, $class));
        }

        try {
            return $reflection->newInstanceArgs($arguments);
        } catch (Throwable $e) {
            throw new StructuredHydrationException("Cannot hydrate {$class}".($path === '' ? '' : " at [{$path}]").': the constructor rejected the values ('.$e->getMessage().').', previous: $e);
        }
    }

    private static function value(?ReflectionType $type, mixed $value, string $path, string $owner, ?string $listOf): mixed
    {
        if ($type === null) {
            return $value;
        }
        if ($value === null) {
            return $type->allowsNull() ? null : throw StructuredHydrationException::at($owner, $path, (string) $type, null);
        }
        if ($type instanceof ReflectionNamedType) {
            return self::named($type->getName(), $value, $path, $owner, $listOf);
        }
        if (! $type instanceof ReflectionUnionType) {
            throw StructuredHydrationException::at($owner, $path, (string) $type.' (intersection types are not supported)', $value);
        }

        foreach ($type->getTypes() as $member) {
            if ($member instanceof ReflectionNamedType && $member->getName() !== 'null') {
                try {
                    return self::named($member->getName(), $value, $path, $owner, $listOf);
                } catch (StructuredHydrationException) {
                    continue;
                }
            }
        }

        throw StructuredHydrationException::at($owner, $path, (string) $type, $value);
    }

    private static function named(string $name, mixed $value, string $path, string $owner, ?string $listOf): mixed
    {
        $ok = match ($name) {
            'mixed' => true,
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => null,
        };
        if ($ok === false) {
            throw StructuredHydrationException::at($owner, $path, $name, $value);
        }
        if ($name === 'array' && $listOf !== null) {
            if (! array_is_list((array) $value)) {
                throw StructuredHydrationException::at($owner, $path, "list<{$listOf}>", $value);
            }

            return array_map(fn (mixed $item, int $i): mixed => $item === null ? throw StructuredHydrationException::at($owner, "{$path}.{$i}", $listOf, null) : self::named($listOf, $item, "{$path}.{$i}", $owner, null), (array) $value, array_keys((array) $value));
        }
        if ($ok === true) {
            return $name === 'float' ? (float) $value : $value;
        }

        if (is_a($name, BackedEnum::class, true)) {
            $backing = (string) (new ReflectionEnum($name))->getBackingType();
            $cases = implode('|', array_map(fn (BackedEnum $case): string => (string) $case->value, $name::cases()));
            $typed = $backing === 'int' ? is_int($value) : is_string($value);

            return ($typed ? $name::tryFrom($value) : null) ?? throw StructuredHydrationException::at($owner, $path, "one of [{$cases}]", $value);
        }
        if (is_a($name, DateTimeInterface::class, true)) {
            $concrete = interface_exists($name) ? DateTimeImmutable::class : $name;
            try {
                return is_string($value) ? new $concrete($value) : throw new \InvalidArgumentException;
            } catch (Throwable) {
                throw StructuredHydrationException::at($owner, $path, 'a date-time string', $value);
            }
        }
        if (class_exists($name)) {
            return self::object($name, $value, $path);
        }

        throw StructuredHydrationException::at($owner, $path, $name.' (unsupported type)', $value);
    }

    /**
     * @param  \ReflectionAttribute<ListOf>|null  $attribute
     */
    private static function listType(?\ReflectionAttribute $attribute, ?string $docblock, string $name, string $owner): ?string
    {
        $type = $attribute?->newInstance()->type;
        $quoted = preg_quote($name, '/');
        if ($type === null && $docblock !== null && preg_match('/@param\s+(?:(?:list|array)<([\w\\\\]+)>|([\w\\\\]+)\[\])\s+\$'.$quoted.'\b/', $docblock, $m) === 1) {
            $type = $m[1] !== '' ? $m[1] : $m[2];
        }
        if ($type === null || in_array($type, ['int', 'float', 'string', 'bool', 'array', 'mixed'], true) || class_exists($type) || enum_exists($type)) {
            return $type === null ? null : ltrim($type, '\\');
        }
        $sibling = substr($owner, 0, (int) strrpos($owner, '\\') + 1).$type;

        return class_exists($sibling) || enum_exists($sibling) ? $sibling : $type;
    }

    private static function join(string $path, string $key): string
    {
        return $path === '' ? $key : "{$path}.{$key}";
    }
}
