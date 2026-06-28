<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Otlp;

/**
 * Deterministic conversion of internal trace/span identifiers to OTLP-valid hex IDs.
 *
 * OTLP requires 32-hex-char trace IDs (16 bytes) and 16-hex-char span IDs (8 bytes).
 * Internal IDs (executionId, uniqid('span_', true)) are hashed so parent/child
 * relationships are preserved across the same conversion function.
 */
final class OtlpIdEncoder
{
    public static function traceId(string $internalId): string
    {
        return substr(hash('sha256', 'trace:'.$internalId), 0, 32);
    }

    public static function spanId(string $internalId): string
    {
        return substr(hash('sha256', 'span:'.$internalId), 0, 16);
    }

    public static function isValidTraceId(string $traceId): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', $traceId);
    }

    public static function isValidSpanId(string $spanId): bool
    {
        return (bool) preg_match('/^[0-9a-f]{16}$/', $spanId);
    }
}
