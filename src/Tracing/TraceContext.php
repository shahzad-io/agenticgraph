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

    private ?string $currentSpanType = null;

    /** @var array<int, string> */
    private array $spanStack = [];

    /** @var array<int, string> */
    private array $spanTypeStack = [];

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
    public function startSpan(string $nodeName, string $spanType = 'custom'): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $spanId = uniqid('span_', true);

        // Push current span onto stack (becomes parent)
        if ($this->currentSpanId !== null) {
            $this->spanStack[] = $this->currentSpanId;
            $this->spanTypeStack[] = $this->currentSpanType ?? 'custom';
        }

        $this->currentSpanId = $spanId;
        $this->currentSpanType = $spanType;

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
        $this->currentSpanType = array_pop($this->spanTypeStack);
    }

    public function getCurrentSpanType(): ?string
    {
        return $this->currentSpanType;
    }

    public function isInsideToolSpan(): bool
    {
        return $this->currentSpanType === 'tool';
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

    /**
     * Return the container-bound trace context for the current execution, if any.
     */
    public static function tryGet(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }

    /**
     * Convert TraceContext to array for serialization (e.g., checkpoint storage).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'root_span_id' => $this->rootSpanId,
            'current_span_id' => $this->currentSpanId,
            'current_span_type' => $this->currentSpanType,
            'span_stack' => $this->spanStack,
            'span_type_stack' => $this->spanTypeStack,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * Create TraceContext from array (e.g., checkpoint restoration).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $executionId = $data['execution_id'] ?? uniqid('exec_', true);
        $rootSpanId = $data['root_span_id'] ?? uniqid('span_', true);

        $context = new self($executionId, $rootSpanId);

        // Restore span stack and current span
        if (isset($data['span_stack']) && is_array($data['span_stack'])) {
            $context->spanStack = $data['span_stack'];
        }

        if (isset($data['span_type_stack']) && is_array($data['span_type_stack'])) {
            $context->spanTypeStack = $data['span_type_stack'];
        }

        if (isset($data['current_span_id']) && is_string($data['current_span_id'])) {
            $context->currentSpanId = $data['current_span_id'];
        }

        if (isset($data['current_span_type']) && is_string($data['current_span_type'])) {
            $context->currentSpanType = $data['current_span_type'];
        }

        return $context;
    }
}
