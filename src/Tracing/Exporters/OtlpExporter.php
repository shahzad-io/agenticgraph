<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Exporters;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Otlp\OtlpPayloadBuilder;

/**
 * OSS tracer exporter that flushes traces to an OTLP/HTTP JSON endpoint.
 *
 * Compatible with Jaeger, Grafana Tempo, Honeycomb, Datadog, and other OTLP collectors.
 * Configure via agenticgraph.tracing.exporter=otlp and tracing.otlp.* settings.
 */
final class OtlpExporter implements TracerExporter
{
    /** @var array<string, array<string, mixed>> */
    private array $traces = [];

    /** @var array<int, array<string, mixed>> */
    private array $spans = [];

    /** @var array<int, array<string, mixed>> */
    private array $llmCalls = [];

    /** @var array<string, Carbon> */
    private array $traceStarts = [];

    /** @var array<string, true> */
    private array $flushedTraceIds = [];

    /** @var array<string, bool> */
    private array $sampledTraces = [];

    private int $captureDepth = 0;

    private bool $enabled = false;

    private string $endpoint;

    /** @var array<string, string> */
    private array $headers;

    private string $protocol;

    public function __construct(
        private readonly OtlpPayloadBuilder $payloadBuilder = new OtlpPayloadBuilder,
    ) {
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

    public function isCapturing(): bool
    {
        return $this->captureDepth > 0;
    }

    public function getTraceStartTime(string $traceId): ?Carbon
    {
        return $this->traceStarts[$traceId] ?? null;
    }

    public function startTrace(
        string $traceId,
        string $graphName,
        array $initialStateKeys = [],
        ?\DateTimeImmutable $startedAt = null,
        ?array $initialStateData = null
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        $sampled = $this->shouldSampleTrace();
        $this->sampledTraces[$traceId] = $sampled;
        $startTime = $startedAt ? Carbon::instance($startedAt) : now();
        $this->traceStarts[$traceId] = $startTime;

        $this->traces[$traceId] = [
            'id' => $traceId,
            'graph_name' => $graphName,
            'status' => 'running',
            'started_at' => $startTime->toIso8601String(),
            'initial_state_keys' => $initialStateKeys,
            'sampled' => $sampled,
            'exporter' => 'otlp',
            'resource' => [
                'service.name' => (string) config('agenticgraph.tracing.service.name', 'agenticgraph'),
                'service.version' => (string) config('agenticgraph.tracing.service.version', 'unknown'),
                'deployment.environment' => (string) config('agenticgraph.tracing.environment', 'local'),
            ],
            'instrumentation_scope' => (string) config('agenticgraph.tracing.instrumentation_scope', 'agenticgraph'),
        ];

        if ($initialStateData !== null && $initialStateData !== []) {
            $this->traces[$traceId]['initial_state'] = $initialStateData;
        }
    }

    public function addSpan(array $span): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $traceId = $span['trace_id'] ?? null;
        if (! $this->isSampled(is_string($traceId) ? $traceId : null)) {
            return;
        }

        $this->whileCapturing(function () use ($span): void {
            $this->spans[] = $span;
        });
    }

    public function addLlmCall(array $call): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $traceId = $call['trace_id'] ?? null;
        if (! $this->isSampled(is_string($traceId) ? $traceId : null)) {
            return;
        }

        $this->whileCapturing(function () use ($call): void {
            $this->llmCalls[] = $call;
        });
    }

    public function completeTrace(
        string $traceId,
        array $finalStateKeys = [],
        float $durationMs = 0,
        ?array $finalStateData = null
    ): void {
        if (! $this->isEnabled() || ! isset($this->traces[$traceId]) || isset($this->flushedTraceIds[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'completed';
        $startedAt = $this->traceStarts[$traceId] ?? null;
        if ($startedAt) {
            $this->traces[$traceId]['ended_at'] = $startedAt
                ->copy()
                ->addMilliseconds((int) $durationMs)
                ->toIso8601String();
        } else {
            $this->traces[$traceId]['ended_at'] = now()->toIso8601String();
        }
        $this->traces[$traceId]['duration_ms'] = $durationMs;
        $this->traces[$traceId]['final_state_keys'] = $finalStateKeys;

        if ($finalStateData !== null && $finalStateData !== []) {
            $this->traces[$traceId]['final_state'] = $finalStateData;
        }

        if (! $this->isSampled($traceId)) {
            $this->reset();

            return;
        }

        $this->flush($traceId);
    }

    public function failTrace(
        string $traceId,
        \Throwable $exception,
        float $durationMs = 0,
        ?array $finalStateData = null
    ): void {
        if (! $this->isEnabled() || ! isset($this->traces[$traceId]) || isset($this->flushedTraceIds[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'failed';
        $startedAt = $this->traceStarts[$traceId] ?? null;
        if ($startedAt) {
            $this->traces[$traceId]['ended_at'] = $startedAt
                ->copy()
                ->addMilliseconds((int) $durationMs)
                ->toIso8601String();
        } else {
            $this->traces[$traceId]['ended_at'] = now()->toIso8601String();
        }
        $this->traces[$traceId]['duration_ms'] = $durationMs;
        $this->traces[$traceId]['error'] = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
        ];

        if ($finalStateData !== null && $finalStateData !== []) {
            $this->traces[$traceId]['final_state'] = $finalStateData;
        }

        if (! $this->isSampled($traceId)) {
            if (! $this->shouldAlwaysSampleOnError()) {
                $this->reset();

                return;
            }
        }

        $this->flush($traceId);
    }

    public function interruptTrace(string $traceId, string $reason): void
    {
        if (! $this->isEnabled() || ! isset($this->traces[$traceId])) {
            return;
        }

        $this->traces[$traceId]['status'] = 'interrupted';
        $this->traces[$traceId]['interrupt_reason'] = $reason;
        $this->traces[$traceId]['interrupted_at'] = now()->toIso8601String();

        if (! $this->isSampled($traceId)) {
            $this->reset();

            return;
        }

        $this->flush($traceId);
    }

    public function resumeTrace(string $traceId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if (isset($this->traces[$traceId])) {
            $this->traces[$traceId]['status'] = 'running';
            $this->traces[$traceId]['resumed_at'] = now()->toIso8601String();
            unset($this->traces[$traceId]['interrupt_reason']);
            unset($this->traces[$traceId]['interrupted_at']);
            unset($this->flushedTraceIds[$traceId]);
        }
    }

    public function flush(string $traceId): void
    {
        if (! $this->isEnabled() || empty($this->traces[$traceId])) {
            $this->reset();

            return;
        }

        if (isset($this->flushedTraceIds[$traceId])) {
            return;
        }

        $this->flushedTraceIds[$traceId] = true;

        $payload = $this->whileCapturing(function () use ($traceId): array {
            $localSpans = array_values(array_filter(
                $this->spans,
                fn ($s) => ($s['trace_id'] ?? '') === $traceId
            ));
            $localCalls = array_values(array_filter(
                $this->llmCalls,
                fn ($c) => ($c['trace_id'] ?? '') === $traceId
            ));

            return $this->payloadBuilder->build($this->traces[$traceId], $localSpans, $localCalls);
        });

        $this->sendPayload($payload);
        $this->reset();
    }

    /**
     * @return array{resourceSpans: list<array<string, mixed>>}
     */
    public function buildPayloadForTrace(string $traceId): array
    {
        $localSpans = array_values(array_filter(
            $this->spans,
            fn ($s) => ($s['trace_id'] ?? '') === $traceId
        ));
        $localCalls = array_values(array_filter(
            $this->llmCalls,
            fn ($c) => ($c['trace_id'] ?? '') === $traceId
        ));

        return $this->payloadBuilder->build($this->traces[$traceId] ?? ['id' => $traceId], $localSpans, $localCalls);
    }

    /**
     * @param  array{resourceSpans: list<array<string, mixed>>}  $payload
     */
    private function sendPayload(array $payload): void
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

    private function shouldSampleTrace(): bool
    {
        $rate = (float) config('agenticgraph.tracing.sampling.rate', 1.0);
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < $rate;
    }

    private function isSampled(?string $traceId): bool
    {
        if ($traceId === null || $traceId === '') {
            return false;
        }

        return $this->sampledTraces[$traceId] ?? true;
    }

    private function shouldAlwaysSampleOnError(): bool
    {
        return (bool) config('agenticgraph.tracing.sampling.always_on_error', true);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function whileCapturing(callable $callback): mixed
    {
        $this->captureDepth++;

        try {
            return $callback();
        } finally {
            $this->captureDepth--;
        }
    }

    private function reset(): void
    {
        $this->traces = [];
        $this->spans = [];
        $this->llmCalls = [];
        $this->traceStarts = [];
        $this->flushedTraceIds = [];
        $this->sampledTraces = [];
        $this->captureDepth = 0;
    }
}
