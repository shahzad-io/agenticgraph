<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Instrumentation;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Shahzad\AgenticGraph\Tracing\CaptureDepth;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\DbQueryType;
use Shahzad\AgenticGraph\Tracing\InstrumentationGate;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceTime;

/**
 * Database query instrumentation for tracing.
 * Extracted from service provider to improve bootstrap performance.
 */
class DatabaseInstrumentation
{
    public function __construct(
        private readonly TracerExporter $exporter,
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

        $traceContext = TraceContext::tryGet();
        if ($traceContext === null) {
            return;
        }

        if (! $this->exporter->isEnabled()) {
            return;
        }

        if (! InstrumentationGate::shouldCaptureDb()) {
            return;
        }

        $executionId = $traceContext->getExecutionId();
        $spanId = $traceContext->getSpanId();
        $durationMs = (float) $event->time;
        $status = $durationMs > $this->slowThresholdMs ? 'slow' : 'success';
        $sql = trim(preg_replace('/\s+/', ' ', $event->sql) ?? $event->sql);
        $endedAt = TraceTime::now();
        $startedAt = TraceTime::addMs($endedAt, -$durationMs);

        $span = [
            'id' => uniqid('span_', true),
            'trace_id' => $executionId,
            'parent_id' => $spanId,
            'node_name' => 'db.query',
            'node_type' => 'db',
            'span_type' => 'db',
            'status' => $status,
            'span_kind' => 'client',
            'status_code' => 'OK',
            'started_at' => TraceTime::format($startedAt),
            'ended_at' => TraceTime::format($endedAt),
            'duration_ms' => $durationMs,
            'db.connection' => $event->connectionName,
            'db.type' => DbQueryType::fromSql($sql),
            'db.bindings_count' => count($event->bindings),
            // QueryExecuted does not expose row counts; PDO rowCount() is unreliable for SELECT
            // (especially MySQL). Populating this requires upstream Laravel support or invasive
            // connection wrapping — leave null so the dashboard does not show misleading zeros.
            'db.rows_affected' => null,
        ];

        $capturedSql = CaptureDepth::dbSqlForSpan($sql);
        if ($capturedSql !== null) {
            $span['db.sql'] = $capturedSql;
        }

        $this->exporter->addSpan($span);
    }
}
