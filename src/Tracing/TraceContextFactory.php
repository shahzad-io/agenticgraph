<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing;

final class TraceContextFactory
{
    public function create(string $executionId, ?string $rootSpanId = null): TraceContext
    {
        return new TraceContext(
            executionId: $executionId,
            rootSpanId: $rootSpanId ?? uniqid('span_', true)
        );
    }
}
