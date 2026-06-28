<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Otlp;

/**
 * Maps PHP values to OTLP JSON attribute value objects.
 */
final class OtlpAttributeMapper
{
    /**
     * @return array{key: string, value: array<string, mixed>}
     */
    public static function attribute(string $key, mixed $value): array
    {
        return [
            'key' => $key,
            'value' => self::value($value),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $prefix
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    public static function flatten(array $data, array $prefix = []): array
    {
        $attributes = [];

        foreach ($data as $key => $value) {
            $attributeKey = $prefix === []
                ? (string) $key
                : implode('.', [...$prefix, (string) $key]);

            if (is_array($value)) {
                if (self::isListArray($value)) {
                    $attributes[] = self::attribute($attributeKey, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
                } else {
                    $attributes = [...$attributes, ...self::flatten($value, [...$prefix, (string) $key])];
                }

                continue;
            }

            $attributes[] = self::attribute($attributeKey, $value);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function value(mixed $value): array
    {
        if ($value === null) {
            return ['stringValue' => ''];
        }

        if (is_bool($value)) {
            return ['boolValue' => $value];
        }

        if (is_int($value)) {
            return ['intValue' => (string) $value];
        }

        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        return ['stringValue' => (string) $value];
    }

    /**
     * @param  array<mixed>  $array
     */
    private static function isListArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }
}
