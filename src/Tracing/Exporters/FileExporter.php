<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Exporters;

use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Tracing\TraceTime;

/**
 * Writes the trace envelope to disk, one file per flushed segment:
 * {path}/{trace_id}/{Ymd-His-u}.json (the same layout as the ingest dev sink).
 */
final class FileExporter extends BufferedTraceExporter
{
    private readonly string $path;

    public function __construct(?string $path = null)
    {
        parent::__construct();

        $this->path = rtrim($path ?? (string) config('agenticgraph.tracing.file.path', storage_path('app/agenticgraph/traces')), '/');
    }

    public function isEnabled(): bool
    {
        return (bool) config('agenticgraph.tracing.enabled', false) && $this->path !== '';
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(array $payload): void
    {
        // Trace ids become directory names; keep them inside the configured path.
        $traceId = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($payload['trace']['id'] ?? 'unknown'));
        $directory = $this->path.'/'.(trim((string) $traceId, '.') ?: 'unknown');
        $file = $directory.'/'.TraceTime::now()->format('Ymd-His-u').'.json';

        try {
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new \RuntimeException("Cannot create trace directory [{$directory}]");
            }

            file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), LOCK_EX);
        } catch (\Throwable $e) {
            // Silent fail - don't break the user's application
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                Log::warning('agenticgraph file trace export failed', ['file' => $file, 'error' => $e->getMessage()]);
            }
        }
    }
}
