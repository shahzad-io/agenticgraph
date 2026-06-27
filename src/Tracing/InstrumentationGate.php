<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

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

    public static function shouldCaptureDb(): bool
    {
        if ((bool) config('agenticgraph.tracing.capture.db', false)) {
            return true;
        }

        return TraceContext::tryGet()?->isInsideToolSpan() ?? false;
    }
}
