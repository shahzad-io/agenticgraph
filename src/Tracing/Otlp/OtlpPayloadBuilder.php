<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Otlp;

/**
 * Builds OTLP/HTTP JSON trace export payloads from internal trace buffers.
 */
final class OtlpPayloadBuilder
{
    /**
     * @param  array<string, mixed>  $trace
     * @param  list<array<string, mixed>>  $spans
     * @param  list<array<string, mixed>>  $llmCalls
     * @return array{resourceSpans: list<array<string, mixed>>}
     */
    public function build(array $trace, array $spans, array $llmCalls = []): array
    {
        $internalTraceId = (string) ($trace['id'] ?? '');
        $otlpTraceId = OtlpIdEncoder::traceId($internalTraceId);

        /** @var array<string, string> $spanIdMap */
        $spanIdMap = [];
        foreach ($spans as $span) {
            if (! isset($span['id'])) {
                continue;
            }

            $spanIdMap[(string) $span['id']] = OtlpIdEncoder::spanId((string) $span['id']);
        }

        /** @var array<string, array<string, mixed>> $llmBySpanId */
        $llmBySpanId = [];
        foreach ($llmCalls as $call) {
            $spanId = $call['span_id'] ?? null;
            if (is_string($spanId) && $spanId !== '') {
                $llmBySpanId[$spanId] = $call;
            }
        }

        $otlpSpans = [];
        foreach ($spans as $span) {
            $internalSpanId = (string) ($span['id'] ?? '');
            $llmCall = $llmBySpanId[$internalSpanId] ?? null;
            $otlpSpans[] = $this->mapSpan($span, $otlpTraceId, $spanIdMap, $llmCall);
        }

        $scopeName = (string) ($trace['instrumentation_scope'] ?? config('agenticgraph.tracing.instrumentation_scope', 'agenticgraph'));

        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => $this->resourceAttributes($trace),
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => ['name' => $scopeName],
                            'spans' => $otlpSpans,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function resourceAttributes(array $trace): array
    {
        $resource = is_array($trace['resource'] ?? null) ? $trace['resource'] : [];

        $attributes = [
            OtlpAttributeMapper::attribute(
                'service.name',
                (string) ($resource['service.name'] ?? config('agenticgraph.tracing.service.name', 'agenticgraph'))
            ),
            OtlpAttributeMapper::attribute(
                'service.version',
                (string) ($resource['service.version'] ?? config('agenticgraph.tracing.service.version', 'unknown'))
            ),
            OtlpAttributeMapper::attribute(
                'deployment.environment',
                (string) ($resource['deployment.environment'] ?? config('agenticgraph.tracing.environment', 'local'))
            ),
            OtlpAttributeMapper::attribute('agenticgraph.graph_name', (string) ($trace['graph_name'] ?? '')),
            OtlpAttributeMapper::attribute('agenticgraph.trace_status', (string) ($trace['status'] ?? 'running')),
        ];

        if (isset($trace['id'])) {
            $attributes[] = OtlpAttributeMapper::attribute('agenticgraph.execution_id', (string) $trace['id']);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $span
     * @param  array<string, string>  $spanIdMap
     * @param  array<string, mixed>|null  $llmCall
     * @return array<string, mixed>
     */
    private function mapSpan(array $span, string $otlpTraceId, array $spanIdMap, ?array $llmCall): array
    {
        $internalSpanId = (string) ($span['id'] ?? uniqid('span_', true));
        $otlpSpanId = $spanIdMap[$internalSpanId] ?? OtlpIdEncoder::spanId($internalSpanId);

        $mapped = [
            'traceId' => $otlpTraceId,
            'spanId' => $otlpSpanId,
            'name' => (string) ($span['node_name'] ?? $span['span_type'] ?? 'span'),
            'kind' => $this->mapSpanKind($span),
            'startTimeUnixNano' => $this->toUnixNanos((string) ($span['started_at'] ?? '')),
            'endTimeUnixNano' => $this->toUnixNanos((string) ($span['ended_at'] ?? $span['started_at'] ?? '')),
            'attributes' => $this->spanAttributes($span, $llmCall),
            'status' => $this->mapStatus($span),
        ];

        $parentInternalId = $span['parent_id'] ?? null;
        if (is_string($parentInternalId) && $parentInternalId !== '') {
            $mapped['parentSpanId'] = $spanIdMap[$parentInternalId] ?? OtlpIdEncoder::spanId($parentInternalId);
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $span
     * @param  array<string, mixed>|null  $llmCall
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function spanAttributes(array $span, ?array $llmCall): array
    {
        $reserved = ['id', 'trace_id', 'parent_id', 'started_at', 'ended_at', 'node_name', 'span_kind', 'status', 'status_code', 'error', 'http'];
        $payload = [];

        foreach ($span as $key => $value) {
            if (in_array($key, $reserved, true)) {
                continue;
            }

            $payload[$key] = $value;
        }

        if (isset($span['span_type'])) {
            $payload['agenticgraph.span_type'] = $span['span_type'];
        }

        if (isset($span['http']) && is_array($span['http'])) {
            $payload = [...$payload, ...$this->prefixKeys($span['http'], 'http')];
        }

        if (isset($span['error']) && is_array($span['error'])) {
            $payload = [...$payload, ...$this->prefixKeys($span['error'], 'exception')];
        }

        foreach ($span as $key => $value) {
            if (str_starts_with($key, 'db.')) {
                $payload[$key] = $value;
            }
        }

        if ($llmCall !== null) {
            $payload = [...$payload, ...$this->genAiAttributes($llmCall)];
        }

        return OtlpAttributeMapper::flatten($payload);
    }

    /**
     * @param  array<string, mixed>  $llmCall
     * @return array<string, mixed>
     */
    private function genAiAttributes(array $llmCall): array
    {
        $attributes = [
            'gen_ai.operation.name' => (string) ($llmCall['call_type'] ?? 'chat'),
        ];

        if (isset($llmCall['model'])) {
            $attributes['gen_ai.request.model'] = (string) $llmCall['model'];
            $attributes['gen_ai.system'] = $this->modelProvider((string) $llmCall['model']);
        }

        if (isset($llmCall['prompt_tokens'])) {
            $attributes['gen_ai.usage.input_tokens'] = (int) $llmCall['prompt_tokens'];
        }

        if (isset($llmCall['completion_tokens'])) {
            $attributes['gen_ai.usage.output_tokens'] = (int) $llmCall['completion_tokens'];
        }

        if (isset($llmCall['total_tokens'])) {
            $attributes['gen_ai.usage.total_tokens'] = (int) $llmCall['total_tokens'];
        }

        if (isset($llmCall['cost_usd'])) {
            $attributes['agenticgraph.cost_usd'] = (float) $llmCall['cost_usd'];
        }

        if (isset($llmCall['prompt']) && is_string($llmCall['prompt'])) {
            $attributes['gen_ai.prompt'] = $llmCall['prompt'];
        }

        if (isset($llmCall['response']) && is_string($llmCall['response'])) {
            $attributes['gen_ai.completion'] = $llmCall['response'];
        }

        return $attributes;
    }

    private function modelProvider(string $model): string
    {
        if (str_contains($model, ':')) {
            return explode(':', $model, 2)[0];
        }

        if (str_contains($model, '/')) {
            return explode('/', $model, 2)[0];
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prefixKeys(array $data, string $prefix): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[$prefix.'.'.$key] = $value;
        }

        return $prefixed;
    }

    /**
     * @param  array<string, mixed>  $span
     */
    private function mapSpanKind(array $span): int
    {
        return match (strtolower((string) ($span['span_kind'] ?? 'internal'))) {
            'client' => 3,
            'server' => 2,
            'producer' => 4,
            'consumer' => 5,
            default => 1,
        };
    }

    /**
     * @param  array<string, mixed>  $span
     * @return array{code: int, message?: string}
     */
    private function mapStatus(array $span): array
    {
        $status = strtolower((string) ($span['status'] ?? ''));
        $statusCode = strtoupper((string) ($span['status_code'] ?? ''));

        if ($status === 'failed' || $statusCode === 'ERROR' || isset($span['error'])) {
            $message = null;
            if (isset($span['error']) && is_array($span['error'])) {
                $message = (string) ($span['error']['message'] ?? $span['error']['type'] ?? null);
            }

            return array_filter([
                'code' => 2,
                'message' => $message,
            ]);
        }

        if ($status === 'success' || $statusCode === 'OK') {
            return ['code' => 1];
        }

        return ['code' => 0];
    }

    private function toUnixNanos(string $iso8601): string
    {
        if ($iso8601 === '') {
            return '0';
        }

        try {
            $at = new \DateTimeImmutable($iso8601);
        } catch (\Exception) {
            return '0';
        }

        // String math keeps every microsecond; float math loses them at current epochs.
        return $at->format('U').$at->format('u').'000';
    }
}
