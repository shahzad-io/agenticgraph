<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Exporters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Tracing\Otlp\OtlpPayloadBuilder;

/**
 * OSS tracer exporter that flushes traces to an OTLP/HTTP JSON endpoint.
 *
 * Compatible with Jaeger, Grafana Tempo, Honeycomb, Datadog, and other OTLP collectors.
 * Configure via agenticgraph.tracing.exporter=otlp and tracing.otlp.* settings.
 * Each segment is one export; collectors join them by trace id.
 */
final class OtlpExporter extends BufferedTraceExporter
{
    private bool $enabled = false;

    private string $endpoint;

    /** @var array<string, string> */
    private array $headers;

    private string $protocol;

    public function __construct(
        private readonly OtlpPayloadBuilder $otlpPayloadBuilder = new OtlpPayloadBuilder,
    ) {
        parent::__construct();

        $this->enabled = (bool) config('agenticgraph.tracing.enabled', false)
            && filled(config('agenticgraph.tracing.otlp.endpoint'));
        $this->endpoint = (string) config('agenticgraph.tracing.otlp.endpoint', 'http://localhost:4318/v1/traces');
        $this->protocol = (string) config('agenticgraph.tracing.otlp.protocol', 'http/json');
        $this->headers = $this->resolveHeaders();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * OTLP spans are immutable once exported, so the root graph span is only exported with the
     * final segment (spec I-6). Earlier segments carry their child spans only.
     */
    protected function buildPayload(array $trace, array $segment, array $spans, array $llmCalls): array
    {
        if (! ($segment['final'] ?? false)) {
            $spans = array_values(array_filter(
                $spans,
                fn (array $span): bool => ($span['parent_id'] ?? null) !== null || ($span['span_type'] ?? null) !== 'graph'
            ));
        }

        return $this->otlpPayloadBuilder->build($trace, $spans, $llmCalls);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(array $payload): void
    {
        if ($this->protocol !== 'http/json') {
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                Log::warning('agenticgraph.otlp.unsupported_protocol', [
                    'protocol' => $this->protocol,
                    'supported' => ['http/json'],
                ]);
            }

            return;
        }

        try {
            $request = Http::timeout(5)->withHeaders($this->headers);

            $response = $request->post($this->endpoint, $payload);

            if ($response->failed() && (bool) config('agenticgraph.logging.enabled', true)) {
                Log::warning('agenticgraph.otlp.export_failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                Log::debug('agenticgraph.otlp.export_error', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function resolveHeaders(): array
    {
        $configured = config('agenticgraph.tracing.otlp.headers', []);
        $headers = is_array($configured) ? $configured : [];

        $decoded = json_decode((string) config('agenticgraph.tracing.otlp.headers_json', '{}'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_string($key) && (is_string($value) || is_numeric($value))) {
                    $headers[$key] = (string) $value;
                }
            }
        }

        $headers['Content-Type'] = 'application/json';

        return $headers;
    }
}
