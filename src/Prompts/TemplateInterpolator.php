<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Prompts;

use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;

/**
 * Shared template interpolation with LangChain-style brace escaping.
 *
 * {{ and }} render as literal { and } and are not variable placeholders.
 */
final class TemplateInterpolator
{
    /**
     * @param  array<string, mixed>  $variables
     *
     * @throws TemplateException
     */
    public static function interpolate(string $template, array $variables): string
    {
        $result = '';
        $length = strlen($template);
        $index = 0;

        while ($index < $length) {
            if ($index + 1 < $length && $template[$index] === '{' && $template[$index + 1] === '{') {
                $result .= '{';
                $index += 2;

                continue;
            }

            if ($index + 1 < $length && $template[$index] === '}' && $template[$index + 1] === '}') {
                $result .= '}';
                $index += 2;

                continue;
            }

            if ($template[$index] === '{' && preg_match('/^\{(\w+)\}/', substr($template, $index), $matches)) {
                $key = $matches[1];

                if (! array_key_exists($key, $variables)) {
                    throw new TemplateException('Missing template variable: '.substr($key, 0, 64));
                }

                $result .= self::formatValue($variables[$key]);
                $index += strlen($matches[0]);

                continue;
            }

            $result .= $template[$index];
            $index++;
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public static function extractVariables(string $template): array
    {
        $variables = [];
        $length = strlen($template);
        $index = 0;

        while ($index < $length) {
            if ($index + 1 < $length && $template[$index] === '{' && $template[$index + 1] === '{') {
                $index += 2;

                continue;
            }

            if ($index + 1 < $length && $template[$index] === '}' && $template[$index + 1] === '}') {
                $index += 2;

                continue;
            }

            if ($template[$index] === '{' && preg_match('/^\{(\w+)\}/', substr($template, $index), $matches)) {
                $variables[] = $matches[1];
                $index += strlen($matches[0]);

                continue;
            }

            $index++;
        }

        return array_values(array_unique($variables));
    }

    public static function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return '';
        }

        return (string) $value;
    }
}
