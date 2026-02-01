<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

/**
 * Request-scoped trace context for span tracking.
 * Manages the current execution's trace ID and span hierarchy.
 *
 * This class is now instance-based (not static) to support Octane/concurrent execution.
 * Each execution creates its own TraceContext instance bound to the container.
 */
final class TraceContext
{
    private ?string $currentSpanId = null;

    /** @var array<int, string> */
    private array $spanStack = [];

    private readonly bool $enabled;

    public function __construct(
        private readonly string $executionId,
        private readonly string $rootSpanId,
    ) {
        $this->enabled = (bool) config('agenticgraph.tracing.enabled', false);
        $this->currentSpanId = $this->rootSpanId;
    }

    /**
     * Start a new span within the current trace.
     * Returns the span ID for use in events.
     */
    public function startSpan(string $nodeName): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $spanId = uniqid('span_', true);

        // Push current span onto stack (becomes parent)
        if ($this->currentSpanId !== null) {
            $this->spanStack[] = $this->currentSpanId;
        }

        $this->currentSpanId = $spanId;

        return $spanId;
    }

    /**
     * End the current span and restore parent.
     */
    public function endSpan(): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->currentSpanId = array_pop($this->spanStack);
    }

    /**
     * Get the current execution/trace ID.
     */
    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    /**
     * Get the current span ID.
     */
    public function getSpanId(): ?string
    {
        return $this->currentSpanId;
    }

    public function getRootSpanId(): string
    {
        return $this->rootSpanId;
    }

    /**
     * Get the parent span ID (top of stack).
     */
    public function getParentSpanId(): ?string
    {
        if (empty($this->spanStack)) {
            return null;
        }

        return end($this->spanStack) ?: null;
    }

    /**
     * Check if tracing is currently enabled for this execution.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
