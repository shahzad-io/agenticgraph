<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\BufferedTraceExporter;

/**
 * Decides whether broad HTTP/DB auto-instrumentation should emit spans.
 *
 * Default: capture only inside an active tool span (signal). Opt-in broad capture
 * via tracing.capture.http / tracing.capture.db restores the legacy behavior.
 */
final class InstrumentationGate
{
    public static function shouldCaptureHttp(): bool
    {
        if ((bool) config('agenticgraph.tracing.capture.http', false)) {
            return true;
        }

        return TraceContext::tryGet()?->isInsideToolSpan() ?? false;
    }

    /**
     * Runs package bookkeeping I/O (execution store, checkpoints, interrupts, batch dispatch)
     * under the exporter's isCapturing() guard, so it never becomes an http or db span. Only
     * node bodies and tools produce those spans.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function internal(callable $callback): mixed
    {
        if (! (bool) config('agenticgraph.tracing.enabled', false)) {
            return $callback();
        }

        $exporter = self::exporter();

        return $exporter instanceof BufferedTraceExporter ? $exporter->whileCapturing($callback) : $callback();
    }

    /** The exporter is chosen from config at runtime; the declared type keeps larastan from fixing it to one class. */
    private static function exporter(): TracerExporter
    {
        return app(TracerExporter::class);
    }

    public static function shouldCaptureDb(): bool
    {
        if ((bool) config('agenticgraph.tracing.capture.db', false)) {
            return true;
        }

        return TraceContext::tryGet()?->isInsideToolSpan() ?? false;
    }
}
