<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Instrumentation;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class HttpClientInstrumentation
{
    /**
     * @var array<string, array<int, float>>
     */
    private array $requestStarts = [];

    /** @var array<string, true> */
    private array $loggedMissingStarts = [];

    public function __construct(
        protected TracerExporter $exporter
    ) {}

    /**
     * @param  object  $events
     * @return array<string, string>
     */
    public function subscribe($events): array
    {
        return [
            RequestSending::class => 'onRequestSending',
            ResponseReceived::class => 'onResponseReceived',
            ConnectionFailed::class => 'onConnectionFailed',
        ];
    }

    public function onRequestSending(RequestSending $event): void
    {
        if ($this->exporter->isCapturing()) {
            return;
        }

        $clock = ExecutionClock::tryGet();
        if (! $clock) {
            return;
        }

        if (! $this->exporter->isEnabled()) {
            return;
        }

        $executionId = $clock->getExecutionId();
        $requestId = spl_object_id($event->request);

        $this->storeRequestStart($executionId, $requestId, microtime(true));

        if ($this->shouldLog()) {
            Log::debug('HTTP request sending', [
                'execution_id' => $executionId,
                'request_id' => $requestId,
                'method' => $event->request->method(),
                'url' => $this->safeUrl((string) $event->request->url()),
            ]);
        }
    }

    public function onResponseReceived(ResponseReceived $event): void
    {
        if ($this->exporter->isCapturing()) {
            return;
        }

        $clock = ExecutionClock::tryGet();
        if (! $clock) {
            return;
        }

        if (! $this->exporter->isEnabled()) {
            return;
        }

        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (BindingResolutionException $e) {
        }

        $executionId = $clock->getExecutionId();
        $parentId = $traceContext?->getSpanId() ?? $traceContext?->getRootSpanId();
        $requestId = spl_object_id($event->request);
        $context = [
            'execution_id' => $executionId,
            'request_id' => $requestId,
            'method' => $event->request->method(),
            'url' => $this->safeUrl((string) $event->request->url()),
            'event' => 'response_received',
        ];

        $durationMs = 0;
        $stats = $event->response->handlerStats();
        if (isset($stats['total_time'])) {
            $durationMs = $stats['total_time'] * 1000;
        }

        $spanId = uniqid('span_', true);

        $requestStart = $this->pullRequestStart($executionId, $requestId);
        if (! $requestStart) {
            $context['status_code'] = $event->response->status();
            $context['duration_ms'] = $durationMs;
            if ($this->shouldLogMissingStart($executionId, $requestId, 'response_received')) {
                Log::warning('HTTP response without matching request', $context);
            }
            $this->addFallbackSpan(
                $clock,
                $executionId,
                $parentId,
                $event->request,
                $durationMs,
                $event->response->successful() ? 'success' : 'failed',
                (string) $event->response->status(),
                $event->response->status(),
                $this->resolveResponseSize($event->response)
            );

            return;
        }

        $durationMs = $durationMs > 0 ? $durationMs : (microtime(true) - $requestStart) * 1000;
        $startedAt = $clock->spanStartedAt($requestStart);
        $endedAt = $clock->spanEndedAt($requestStart, $durationMs);
        $http = [
            'method' => $event->request->method(),
            'url' => $event->request->url(),
            'status_code' => $event->response->status(),
        ];
        $responseSize = $this->resolveResponseSize($event->response);
        if ($responseSize !== null) {
            $http['response_size'] = $responseSize;
        }
        $this->exporter->addSpan([
            'id' => $spanId,
            'trace_id' => $executionId,
            'parent_id' => $parentId,
            'node_name' => 'http_request',
            'node_type' => 'http',
            'span_type' => 'http',
            'status' => $event->response->successful() ? 'success' : 'failed',
            'span_kind' => 'client',
            'status_code' => (string) $event->response->status(),
            'started_at' => $startedAt->format(DATE_ATOM),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
            'http' => $http,
        ]);

        if ($this->shouldLog()) {
            Log::debug('HTTP response received', array_merge($context, [
                'status_code' => $event->response->status(),
                'duration_ms' => $durationMs,
            ]));
        }
    }

    public function onConnectionFailed(ConnectionFailed $event): void
    {
        if ($this->exporter->isCapturing()) {
            return;
        }

        $clock = ExecutionClock::tryGet();
        if (! $clock) {
            return;
        }

        if (! $this->exporter->isEnabled()) {
            return;
        }

        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (BindingResolutionException $e) {
        }

        $executionId = $clock->getExecutionId();
        $parentId = $traceContext?->getSpanId() ?? $traceContext?->getRootSpanId();
        $requestId = spl_object_id($event->request);
        $context = [
            'execution_id' => $executionId,
            'request_id' => $requestId,
            'method' => $event->request->method(),
            'url' => $this->safeUrl((string) $event->request->url()),
            'event' => 'connection_failed',
        ];

        $requestStart = $this->pullRequestStart($executionId, $requestId);
        if (! $requestStart) {
            $context['duration_ms'] = 0;
            $context['error_type'] = get_class($event->exception);
            if ($this->shouldLogMissingStart($executionId, $requestId, 'connection_failed')) {
                Log::warning('HTTP connection failed without matching request', $context);
            }
            $this->addFallbackSpan(
                $clock,
                $executionId,
                $parentId,
                $event->request,
                0,
                'failed',
                '0',
                null,
                0,
                $event->exception->getMessage()
            );

            return;
        }

        $durationMs = (microtime(true) - $requestStart) * 1000;
        $startedAt = $clock->spanStartedAt($requestStart);
        $endedAt = $clock->spanEndedAt($requestStart, $durationMs);
        $this->exporter->addSpan([
            'id' => uniqid('span_', true),
            'trace_id' => $executionId,
            'parent_id' => $parentId,
            'node_name' => 'http_request',
            'node_type' => 'http',
            'span_type' => 'http',
            'status' => 'failed',
            'span_kind' => 'client',
            'status_code' => '0',
            'started_at' => $startedAt->format(DATE_ATOM),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
            'http' => [
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'status_code' => null,
                'response_size' => 0,
                'error' => $event->exception->getMessage(),
            ],
        ]);

        if ($this->shouldLog()) {
            Log::debug('HTTP connection failed', array_merge($context, [
                'duration_ms' => $durationMs,
                'error_type' => get_class($event->exception),
            ]));
        }
    }

    public function cleanupExecution(string $executionId): void
    {
        if (! isset($this->requestStarts[$executionId])) {
            return;
        }

        unset($this->requestStarts[$executionId]);
    }

    private function shouldLog(): bool
    {
        return (bool) config('agenticgraph.logging.enabled', true);
    }

    private function shouldLogMissingStart(string $executionId, int $requestId, string $event): bool
    {
        if (! $this->shouldLog()) {
            return false;
        }

        $key = "{$executionId}:{$requestId}:{$event}";
        if (isset($this->loggedMissingStarts[$key])) {
            return false;
        }

        $this->loggedMissingStarts[$key] = true;

        return true;
    }

    private function addFallbackSpan(
        ExecutionClock $clock,
        string $executionId,
        ?string $parentId,
        Request $request,
        float $durationMs,
        string $status,
        string $statusCode,
        ?int $httpStatusCode,
        ?int $responseSize,
        ?string $errorMessage = null
    ): void {
        $durationMs = max($durationMs, 0);
        $endMicrotime = microtime(true);
        $startMicrotime = $durationMs > 0 ? $endMicrotime - ($durationMs / 1000) : $endMicrotime;
        $startedAt = $clock->spanStartedAt($startMicrotime);
        $endedAt = $clock->spanEndedAt($startMicrotime, $durationMs);

        $http = [
            'method' => $request->method(),
            'url' => $request->url(),
            'status_code' => $httpStatusCode,
        ];
        if ($responseSize !== null) {
            $http['response_size'] = $responseSize;
        }
        if ($errorMessage !== null) {
            $http['error'] = $errorMessage;
        }

        $this->exporter->addSpan([
            'id' => uniqid('span_', true),
            'trace_id' => $executionId,
            'parent_id' => $parentId,
            'node_name' => 'http_request',
            'node_type' => 'http',
            'span_type' => 'http',
            'status' => $status,
            'span_kind' => 'client',
            'status_code' => $statusCode,
            'started_at' => $startedAt->format(DATE_ATOM),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
            'http' => $http,
        ]);
    }

    private function storeRequestStart(string $executionId, int $requestId, float $start): void
    {
        $this->requestStarts[$executionId] ??= [];
        $this->requestStarts[$executionId][$requestId] = $start;
        if (count($this->requestStarts) > 10000) {
            $this->requestStarts = [];
        }
    }

    private function pullRequestStart(string $executionId, int $requestId): ?float
    {
        $start = $this->requestStarts[$executionId][$requestId] ?? null;
        if ($start !== null) {
            unset($this->requestStarts[$executionId][$requestId]);

            return (float) $start;
        }

        return null;
    }

    /**
     * Observe response size without consuming streamed/SSE bodies that Prism reads incrementally.
     */
    private function resolveResponseSize(Response $response): ?int
    {
        if ($this->isStreamedResponse($response)) {
            $contentLength = $response->header('Content-Length');

            if ($contentLength !== '' && ctype_digit($contentLength)) {
                return (int) $contentLength;
            }

            return null;
        }

        return strlen($response->body());
    }

    private function isStreamedResponse(Response $response): bool
    {
        $contentType = strtolower($response->header('Content-Type'));
        if (str_contains($contentType, 'text/event-stream')) {
            return true;
        }

        return ! $response->toPsrResponse()->getBody()->isSeekable();
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return strtok($url, '?') ?: $url;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '/';

        $base = '';
        if ($scheme && $host) {
            $base = $scheme.'://'.$host;
            if ($port) {
                $base .= ':'.$port;
            }
        }

        return $base.$path;
    }
}
