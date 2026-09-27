<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Author-controllable capture depth for tracing payloads (PII-safe defaults).
 */
final class CaptureDepth
{
    /**
     * Query parameters whose values url.full never carries: ours plus the OTel list at
     * semantic-conventions v1.44.0. Matched case-insensitively, a superset of OTel's case-sensitive rule.
     */
    private const SENSITIVE_QUERY_KEYS = [
        'key', 'api_key', 'apikey', 'token', 'access_token', 'signature', 'sig', 'password',
        'x-amz-signature', 'x-amz-credential', 'x-amz-security-token', 'x-goog-signature',
    ];

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
            'full' => ['tool.args' => self::truncate(self::encodePayload($args))],
            'summary' => ['tool.arg_keys' => self::summarizeArgKeys($args)],
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
            'full' => $text !== null ? ['tool.result' => self::truncate($text)] : [],
            'summary' => $text !== null ? ['tool.result_size' => strlen($text)] : [],
            default => [],
        };
    }

    /**
     * @return array{'http.request.method': string, 'url.full'?: string, 'laravel.http.url_summary'?: string}
     */
    public static function httpRequestFields(string $method, string $url): array
    {
        $fields = ['http.request.method' => $method];

        return match (self::mode('http_urls', 'summary')) {
            'full' => array_merge($fields, ['url.full' => self::redactUrl($url)]),
            'summary' => array_merge($fields, ['laravel.http.url_summary' => self::summarizeHttpUrl($url)]),
            default => $fields,
        };
    }

    /**
     * @return array{'db.query.text'?: string, 'laravel.db.query_summary'?: string}
     */
    public static function dbSqlForSpan(string $sql): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return match (self::mode('db_sql', 'summary')) {
            'full' => ['db.query.text' => (string) self::truncate($normalized)],
            // Our summary is "{VERB} {first table}", not the OTel db.query.summary algorithm.
            'summary' => ['laravel.db.query_summary' => self::summarizeSql($normalized)],
            default => [],
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

        if (! array_is_list($args)) {
            return array_map(strval(...), array_keys($args));
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

    /**
     * OTel url.full: credentials become REDACTED:REDACTED, sensitive query values become REDACTED.
     */
    private static function redactUrl(string $url): string
    {
        $url = preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1REDACTED:REDACTED@', $url) ?? $url;
        if (preg_match('/^([^?#]*)\?([^#]*)(.*)$/s', $url, $parts) !== 1) {
            return $url;
        }

        $query = array_map(function (string $pair): string {
            [$key] = explode('=', $pair, 2);

            return str_contains($pair, '=') && in_array(strtolower(urldecode($key)), self::SENSITIVE_QUERY_KEYS, true)
                ? $key.'=REDACTED'
                : $pair;
        }, explode('&', $parts[2]));

        return $parts[1].'?'.implode('&', $query).$parts[3];
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
