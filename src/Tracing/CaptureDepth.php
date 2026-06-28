<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Author-controllable capture depth for tracing payloads (PII-safe defaults).
 */
final class CaptureDepth
{
    public static function shouldCarryPromptInEvent(): bool
    {
        return (bool) config('agenticgraph.tracing.capture.prompts', false);
    }

    public static function shouldCarryResponseInEvent(): bool
    {
        return (bool) config('agenticgraph.tracing.capture.responses', false);
    }

    public static function promptForEvent(?string $prompt): ?string
    {
        if (! self::shouldCarryPromptInEvent() || $prompt === null) {
            return null;
        }

        return self::truncate($prompt);
    }

    public static function responseForEvent(?string $response): ?string
    {
        if (! self::shouldCarryResponseInEvent() || $response === null) {
            return null;
        }

        return self::truncate($response);
    }

    /**
     * @param  array<int, mixed>  $args
     * @return array<string, mixed>
     */
    public static function toolArgsForSpan(array $args): array
    {
        return match (self::mode('tool_args', 'summary')) {
            'full' => ['tool_args' => self::truncate(self::encodePayload($args))],
            'summary' => ['arg_keys' => self::summarizeArgKeys($args)],
            default => [],
        };
    }

    /**
     * @return array<string, int|string>
     */
    public static function toolResultForSpan(mixed $result): array
    {
        $text = is_string($result) ? $result : self::encodePayload($result);

        return match (self::mode('tool_results', 'none')) {
            'full' => $text !== null ? ['tool_result' => self::truncate($text)] : [],
            'summary' => $text !== null ? ['tool_result_size' => strlen($text)] : [],
            default => [],
        };
    }

    /**
     * @return array{method: string, url?: string, url_summary?: string}
     */
    public static function httpRequestFields(string $method, string $url): array
    {
        $fields = ['method' => $method];

        return match (self::mode('http_urls', 'summary')) {
            'full' => array_merge($fields, ['url' => $url]),
            'summary' => array_merge($fields, ['url_summary' => self::summarizeHttpUrl($url)]),
            default => $fields,
        };
    }

    public static function dbSqlForSpan(string $sql): ?string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return match (self::mode('db_sql', 'summary')) {
            'full' => self::truncate($normalized),
            'summary' => self::summarizeSql($normalized),
            default => null,
        };
    }

    public static function truncate(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $maxLength = (int) config('agenticgraph.tracing.capture.max_length', 2000);
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = substr($text, 0, $maxLength - 15);

        return $truncated.' [...truncated]';
    }

    /**
     * @param  array<int, mixed>  $args
     * @return list<string>
     */
    private static function summarizeArgKeys(array $args): array
    {
        if ($args === []) {
            return [];
        }

        $first = $args[0];
        if (is_array($first)) {
            return array_map(strval(...), array_keys($first));
        }

        return array_map(fn (int $index): string => (string) $index, array_keys($args));
    }

    private static function summarizeHttpUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return strtok($url, '?') ?: $url;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '/';

        $base = '';
        if ($scheme && $host) {
            $base = $scheme.'://'.$host;
            if ($port) {
                $base .= ':'.$port;
            }
        }

        return $base.$path;
    }

    private static function summarizeSql(string $sql): string
    {
        if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|WITH)\b/i', $sql, $matches) !== 1) {
            return self::truncate(substr($sql, 0, 64)) ?? 'query';
        }

        $operation = strtoupper($matches[1]);
        $table = 'unknown';

        if (preg_match('/\bFROM\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $sql, $fromMatch) === 1) {
            $table = $fromMatch[1];
        } elseif (preg_match('/\bINTO\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $sql, $intoMatch) === 1) {
            $table = $intoMatch[1];
        } elseif (preg_match('/\bUPDATE\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $sql, $updateMatch) === 1) {
            $table = $updateMatch[1];
        }

        return "{$operation} {$table}";
    }

    private static function mode(string $key, string $default): string
    {
        $value = strtolower((string) config("agenticgraph.tracing.capture.{$key}", $default));

        return in_array($value, ['none', 'summary', 'full'], true) ? $value : $default;
    }

    private static function encodePayload(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }
}
