<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;

/**
 * Public tracing API for deliberate, context-conditional span emission.
 *
 * Span ID unification: the ID returned by {@see TraceContext::startSpan()} is the
 * same ID passed to {@see TracerExporter::addSpan()} — the stack span and the
 * exported span are one identity (T3 tool-wrap will align to this pattern).
 */
final class Tracer
{
    public function __construct(
        private readonly TracerExporter $exporter,
    ) {}

    /**
     * Run a callback inside a child span when an active trace context exists.
     *
     * @param  callable(): mixed  $callback
     * @param  array<string, mixed>  $attributes
     */
    public function span(string $name, callable $callback, array &$attributes = [], string $spanType = 'custom'): mixed
    {
        $context = TraceContext::tryGet();
        if ($context === null || ! $context->isEnabled()) {
            return $callback();
        }

        $parentSpanId = $context->getSpanId();
        $spanId = $context->startSpan($name, $spanType);
        if ($spanId === null) {
            return $callback();
        }

        $startMicrotime = microtime(true);
        $status = 'success';
        $statusCode = 'OK';
        $error = null;
        $result = null;
        $throwable = null;

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $status = 'failed';
            $statusCode = 'ERROR';
            $error = [
                'type' => get_class($e),
                'message' => $e->getMessage(),
            ];
            $throwable = $e;
        } finally {
            $durationMs = (microtime(true) - $startMicrotime) * 1000;

            if ($this->exporter->isEnabled()) {
                if ($spanType === 'tool') {
                    $attributes = array_merge($attributes, CaptureDepth::toolResultForSpan($result));
                }

                $this->exportSpan(
                    context: $context,
                    spanId: $spanId,
                    parentSpanId: $parentSpanId,
                    name: $name,
                    spanType: $spanType,
                    status: $status,
                    statusCode: $statusCode,
                    startMicrotime: $startMicrotime,
                    durationMs: $durationMs,
                    attributes: $attributes,
                    error: $error,
                );
            }

            $context->endSpan();
        }

        if ($throwable !== null) {
            throw $throwable;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{type: string, message: string}|null  $error
     */
    private function exportSpan(
        TraceContext $context,
        string $spanId,
        ?string $parentSpanId,
        string $name,
        string $spanType,
        string $status,
        string $statusCode,
        float $startMicrotime,
        float $durationMs,
        array $attributes,
        ?array $error,
    ): void {
        $clock = ExecutionClock::tryGet();
        $startedAt = $clock
            ? $clock->spanStartedAt($startMicrotime)
            : (\DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $startMicrotime)) ?: new \DateTimeImmutable);

        if ($clock) {
            $endedAt = $clock->spanEndedAt($startMicrotime, $durationMs);
        } else {
            $durationSeconds = $durationMs / 1000;
            $wholeSeconds = intval($durationSeconds);
            $fractionalSeconds = $durationSeconds - $wholeSeconds;
            $interval = new \DateInterval("PT{$wholeSeconds}S");
            if ($fractionalSeconds > 0) {
                $interval->f = $fractionalSeconds;
            }
            $endedAt = $startedAt->add($interval);
        }

        $span = [
            'id' => $spanId,
            'trace_id' => $context->getExecutionId(),
            'parent_id' => $parentSpanId,
            'node_name' => $name,
            'node_type' => $spanType,
            'span_type' => $spanType,
            'status' => $status,
            'span_kind' => 'internal',
            'status_code' => $statusCode,
            'started_at' => $startedAt->format(DATE_ATOM),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
        ];

        $sanitizedAttributes = $this->sanitizeAttributes($attributes);

        if ($spanType === 'tool') {
            foreach (['tool_type', 'tool_provider', 'tool_call_id', 'input_size', 'output_size'] as $toolKey) {
                if (array_key_exists($toolKey, $sanitizedAttributes)) {
                    $span[$toolKey] = $sanitizedAttributes[$toolKey];
                    unset($sanitizedAttributes[$toolKey]);
                }
            }
        }

        if ($sanitizedAttributes !== []) {
            $span['attributes'] = $sanitizedAttributes;
        }

        if ($error !== null) {
            $span['error'] = $error;
        }

        $this->exporter->addSpan($span);
    }

    /**
     * Honor capture.max_length for string attribute values (full capture-depth is T5).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sanitizeAttributes(array $attributes): array
    {
        $sanitized = [];

        foreach ($attributes as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = CaptureDepth::truncate($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
