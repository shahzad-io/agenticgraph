<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Exporters;

use Illuminate\Support\Facades\Log;

/**
 * OSS tracer exporter that writes the trace envelope to the Laravel log.
 * No AgenticPulse account or API key required.
 */
final class LogExporter extends BufferedTraceExporter
{
    public function isEnabled(): bool
    {
        return (bool) config('agenticgraph.tracing.enabled', false);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(array $payload): void
    {
        if ((bool) config('agenticgraph.logging.enabled', true)) {
            Log::info('agenticgraph.trace', $payload);
        }
    }
}
