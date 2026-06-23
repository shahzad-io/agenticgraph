<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Instrumentation;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * Database query instrumentation for tracing.
 * Extracted from service provider to improve bootstrap performance.
 */
class DatabaseInstrumentation
{
    public function __construct(
        private readonly PulseExporter $exporter,
        private readonly float $slowThresholdMs
    ) {}

    /**
     * Register the database query listener
     */
    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, [$this, 'handleQuery']);
    }

    /**
     * Handle a database query event
     */
    public function handleQuery(QueryExecuted $event): void
    {
        if ($this->exporter->isCapturing()) {
            return;
        }

        // Only instrument if we're in an active trace
        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (BindingResolutionException $e) {
            // TraceContext not bound, tracing not active
            return;
        }

        if (! $this->exporter->isEnabled()) {
            return;
        }

        $executionId = $traceContext->getExecutionId();
        $spanId = $traceContext->getSpanId();
        $durationMs = (float) $event->time;
        $status = $durationMs > $this->slowThresholdMs ? 'slow' : 'success';
        $sql = trim(preg_replace('/\s+/', ' ', $event->sql));
        $startedAt = now()->subMilliseconds((int) $durationMs);

        $this->exporter->addSpan([
            'id' => uniqid('span_', true),
            'trace_id' => $executionId,
            'parent_id' => $spanId,
            'node_name' => 'db.query',
            'node_type' => 'db',
            'span_type' => 'db',
            'status' => $status,
            'span_kind' => 'client',
            'status_code' => 'OK',
            'started_at' => $startedAt->toAtomString(),
            'ended_at' => $startedAt->copy()->addMilliseconds((int) $durationMs)->toAtomString(),
            'duration_ms' => $durationMs,
            'db.connection' => $event->connectionName,
            'db.type' => $event->readWriteType ?? null,
            'db.sql' => $sql,
            'db.bindings_count' => count($event->bindings),
            'db.rows_affected' => null,
        ]);
    }
}
