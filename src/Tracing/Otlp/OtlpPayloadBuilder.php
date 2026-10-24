<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Otlp;

/**
 * Builds OTLP/HTTP JSON trace export payloads from internal trace buffers.
 */
final class OtlpPayloadBuilder
{
    private const LLM_FIELDS = ['model', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost_usd', 'cached', 'streamed', 'thought_tokens', 'cache_read_tokens', 'cache_write_tokens'];

    /** Prism provider names whose OTel gen_ai.provider.name well-known value differs. */
    private const OTEL_PROVIDERS = ['gemini' => 'gcp.gemini', 'mistral' => 'mistral_ai', 'xai' => 'x_ai'];

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
                'deployment.environment.name',
                (string) ($resource['deployment.environment.name'] ?? config('agenticgraph.tracing.environment', 'local'))
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

        foreach ($span['links'] ?? [] as $link) {
            $mapped['links'][] = ['traceId' => OtlpIdEncoder::traceId((string) $link['trace_id']), 'spanId' => OtlpIdEncoder::spanId((string) $link['span_id'])];
        }

        return $mapped;
    }

    /**
     * Wire v1 attributes pass through under their own names; promoted llm fields map to
     * gen_ai.* only where the OTel meaning matches.
     *
     * @param  array<string, mixed>  $span
     * @param  array<string, mixed>|null  $llmCall
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function spanAttributes(array $span, ?array $llmCall): array
    {
        $reserved = ['id', 'trace_id', 'parent_id', 'links', 'started_at', 'ended_at', 'node_name', 'span_kind', 'status', 'status_code', 'error', 'attributes', ...self::LLM_FIELDS];
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

        if (isset($span['attributes']) && is_array($span['attributes'])) {
            $payload = [...$payload, ...$span['attributes']];
        }

        if (isset($span['error']) && is_array($span['error'])) {
            // error.stage is ours, not an OTel exception attribute.
            $error = $span['error'];
            if (isset($error['stage'])) {
                $payload['agenticgraph.error.stage'] = $error['stage'];
                unset($error['stage']);
            }
            $payload = [...$payload, ...$this->prefixKeys($error, 'exception')];
        }

        $spanType = $span['span_type'] ?? null;
        if ($spanType === 'llm') {
            $payload = [...$payload, ...$this->genAiAttributes($span, $llmCall)];
        } elseif ($spanType === 'tool') {
            $payload['gen_ai.operation.name'] = 'execute_tool';
            $payload['gen_ai.tool.name'] = (string) ($span['node_name'] ?? '');
        }

        return OtlpAttributeMapper::flatten($payload);
    }

    /**
     * @param  array<string, mixed>  $span
     * @param  array<string, mixed>|null  $llmCall
     * @return array<string, mixed>
     */
    private function genAiAttributes(array $span, ?array $llmCall): array
    {
        $attributes = [];
        foreach (['total_tokens', 'cost_usd', 'cached', 'thought_tokens', 'cache_read_tokens', 'cache_write_tokens'] as $key) {
            if (isset($span[$key])) {
                $attributes['agenticgraph.'.$key] = $span[$key];
            }
        }

        // A cache hit made no provider request, so it carries no gen_ai.* request or usage data.
        if (($span['cached'] ?? false) === true) {
            return $attributes;
        }

        $provider = isset($span['model']) ? $this->modelProvider((string) $span['model']) : null;
        $operation = $this->operationName($llmCall['call_type'] ?? null, $provider);
        if ($operation !== null) {
            $attributes['gen_ai.operation.name'] = $operation;
        }

        if (isset($span['model'])) {
            $model = (string) $span['model'];
            $attributes['gen_ai.request.model'] = str_contains($model, ':') ? explode(':', $model, 2)[1] : $model;
        }

        if ($provider !== null) {
            $attributes['gen_ai.provider.name'] = self::OTEL_PROVIDERS[$provider] ?? $provider;
        }

        // OTel: input_tokens SHOULD include cached tokens, and the cache attributes are subsets of it. Our
        // prompt_tokens is uncached input (usage semantics v2), so the sum is sent.
        if (isset($span['prompt_tokens'])) {
            $attributes['gen_ai.usage.input_tokens'] = (int) $span['prompt_tokens'] + (int) ($span['cache_read_tokens'] ?? 0) + (int) ($span['cache_write_tokens'] ?? 0);
        }

        if (isset($span['cache_read_tokens'])) {
            $attributes['gen_ai.usage.cache_read.input_tokens'] = (int) $span['cache_read_tokens'];
        }

        if (isset($span['cache_write_tokens'])) {
            $attributes['gen_ai.usage.cache_write.input_tokens'] = (int) $span['cache_write_tokens'];
        }

        if (isset($span['completion_tokens'])) {
            $attributes['gen_ai.usage.output_tokens'] = (int) $span['completion_tokens'];
        }

        if (isset($span['streamed'])) {
            $attributes['gen_ai.request.stream'] = (bool) $span['streamed'];
        }

        return $attributes;
    }

    /**
     * text, structured and tools calls are chat requests (generate_content on Gemini). Other
     * call types (image, audio, mcp) have no exact OTel operation and are left out.
     */
    private function operationName(mixed $callType, ?string $provider): ?string
    {
        return match ($callType) {
            'text', 'structured', 'tools' => $provider === 'gemini' ? 'generate_content' : 'chat',
            'embeddings' => 'embeddings',
            default => null,
        };
    }

    private function modelProvider(string $model): ?string
    {
        if (str_contains($model, ':')) {
            return explode(':', $model, 2)[0];
        }

        return null;
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
