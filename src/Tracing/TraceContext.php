<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Thread-local trace context for span tracking.
 * Manages the current execution's trace ID and span hierarchy.
 */
class TraceContext
{
    private static ?string $currentExecutionId = null;

    private static ?string $currentSpanId = null;

    private static array $spanStack = [];

    private static bool $enabled = false;

    /**
     * Start a new trace context for an execution.
     */
    public static function startTrace(string $executionId): void
    {
        self::$enabled = (bool) config('agenticgraph.tracing.enabled', false);
        if (! self::$enabled) {
            return;
        }

        self::$currentExecutionId = $executionId;
        self::$currentSpanId = null;
        self::$spanStack = [];
    }

    /**
     * Start a new span within the current trace.
     * Returns the span ID for use in events.
     */
    public static function startSpan(string $nodeName): ?string
    {
        if (! self::$enabled) {
            return null;
        }

        $spanId = uniqid('span_', true);

        // Push current span onto stack (becomes parent)
        if (self::$currentSpanId !== null) {
            self::$spanStack[] = self::$currentSpanId;
        }

        self::$currentSpanId = $spanId;

        return $spanId;
    }

    /**
     * End the current span and restore parent.
     */
    public static function endSpan(): void
    {
        if (! self::$enabled) {
            return;
        }

        self::$currentSpanId = array_pop(self::$spanStack);
    }

    /**
     * End the trace context (cleanup).
     */
    public static function endTrace(): void
    {
        self::$currentExecutionId = null;
        self::$currentSpanId = null;
        self::$spanStack = [];
        self::$enabled = false;
    }

    /**
     * Get the current execution/trace ID.
     */
    public static function getExecutionId(): ?string
    {
        return self::$currentExecutionId;
    }

    /**
     * Get the current span ID.
     */
    public static function getSpanId(): ?string
    {
        return self::$currentSpanId;
    }

    /**
     * Get the parent span ID (top of stack).
     */
    public static function getParentSpanId(): ?string
    {
        if (empty(self::$spanStack)) {
            return null;
        }

        return end(self::$spanStack) ?: null;
    }

    /**
     * Check if tracing is currently enabled for this execution.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Reset context (useful for testing).
     */
    public static function reset(): void
    {
        self::endTrace();
    }
}
