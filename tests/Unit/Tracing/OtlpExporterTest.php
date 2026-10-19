<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Shahzad\AgenticGraph\Tracing\Exporters\OtlpExporter;
use Shahzad\AgenticGraph\Tracing\Otlp\OtlpIdEncoder;
use Shahzad\AgenticGraph\Tracing\Otlp\OtlpPayloadBuilder;
use Shahzad\AgenticGraph\Tracing\TracerExporterFactory;

it('encodes trace and span ids as valid otlp hex lengths', function (): void {
    $traceId = OtlpIdEncoder::traceId('exec_abc123');
    $spanId = OtlpIdEncoder::spanId('span_xyz789');

    expect(OtlpIdEncoder::isValidTraceId($traceId))->toBeTrue()
        ->and(OtlpIdEncoder::isValidSpanId($spanId))->toBeTrue()
        ->and(strlen($traceId))->toBe(32)
        ->and(strlen($spanId))->toBe(16)
        ->and(OtlpIdEncoder::traceId('exec_abc123'))->toBe($traceId)
        ->and(OtlpIdEncoder::spanId('span_xyz789'))->toBe($spanId);
});

it('maps internal span tree to valid otlp json preserving parent relationships', function (): void {
    $builder = new OtlpPayloadBuilder;

    $payload = $builder->build(
        trace: [
            'id' => 'exec_tree',
            'graph_name' => 'demo-graph',
            'status' => 'completed',
            'instrumentation_scope' => 'agenticgraph',
            'resource' => [
                'service.name' => 'workflow',
                'service.version' => '1.0.0',
                'deployment.environment.name' => 'testing',
            ],
        ],
        spans: [
            [
                'id' => 'span_graph',
                'trace_id' => 'exec_tree',
                'parent_id' => null,
                'node_name' => 'demo-graph',
                'span_type' => 'graph',
                'span_kind' => 'internal',
                'status' => 'success',
                'status_code' => 'OK',
                'started_at' => '2026-06-27T10:00:00+00:00',
                'ended_at' => '2026-06-27T10:00:02+00:00',
                'duration_ms' => 2000,
            ],
            [
                'id' => 'span_node',
                'trace_id' => 'exec_tree',
                'parent_id' => 'span_graph',
                'node_name' => 'generate',
                'span_type' => 'node',
                'span_kind' => 'internal',
                'status' => 'success',
                'status_code' => 'OK',
                'started_at' => '2026-06-27T10:00:00.500000+00:00',
                'ended_at' => '2026-06-27T10:00:01.500000+00:00',
                'duration_ms' => 1000,
            ],
            [
                'id' => 'span_tool',
                'trace_id' => 'exec_tree',
                'parent_id' => 'span_node',
                'node_name' => 'fetch_rate',
                'span_type' => 'tool',
                'span_kind' => 'internal',
                'status' => 'success',
                'status_code' => 'OK',
                'started_at' => '2026-06-27T10:00:00.600000+00:00',
                'ended_at' => '2026-06-27T10:00:00.900000+00:00',
                'duration_ms' => 300,
                'arg_keys' => ['currency'],
            ],
            [
                'id' => 'span_http',
                'trace_id' => 'exec_tree',
                'parent_id' => 'span_tool',
                'node_name' => 'http_request',
                'span_type' => 'http',
                'span_kind' => 'client',
                'status' => 'success',
                'status_code' => '200',
                'started_at' => '2026-06-27T10:00:00.650000+00:00',
                'ended_at' => '2026-06-27T10:00:00.850000+00:00',
                'duration_ms' => 200,
                'http' => [
                    'method' => 'GET',
                    'url_summary' => 'https://api.example.com/rates',
                    'status_code' => 200,
                ],
            ],
        ],
        llmCalls: [
            [
                'id' => 'llm_1',
                'trace_id' => 'exec_tree',
                'span_id' => 'span_llm',
                'node' => 'generate',
                'call_type' => 'text',
                'model' => 'openai:gpt-4o',
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
                'cost_usd' => 0.75,
            ],
        ],
    );

    expect($payload)->toHaveKey('resourceSpans')
        ->and($payload['resourceSpans'])->toHaveCount(1);

    $resourceSpan = $payload['resourceSpans'][0];
    $otlpSpans = $resourceSpan['scopeSpans'][0]['spans'] ?? [];

    expect($otlpSpans)->toHaveCount(4);

    $otlpTraceId = OtlpIdEncoder::traceId('exec_tree');
    $byInternal = [];
    foreach ($otlpSpans as $span) {
        expect(OtlpIdEncoder::isValidTraceId($span['traceId']))->toBeTrue()
            ->and(OtlpIdEncoder::isValidSpanId($span['spanId']))->toBeTrue()
            ->and($span['traceId'])->toBe($otlpTraceId)
            ->and($span)->toHaveKeys(['name', 'kind', 'startTimeUnixNano', 'endTimeUnixNano', 'attributes', 'status']);

        $internalId = match ($span['name']) {
            'demo-graph' => 'span_graph',
            'generate' => 'span_node',
            'fetch_rate' => 'span_tool',
            'http_request' => 'span_http',
            default => null,
        };

        if ($internalId !== null) {
            $byInternal[$internalId] = $span;
        }
    }

    expect($byInternal['span_graph']['parentSpanId'] ?? null)->toBeNull()
        ->and($byInternal['span_node']['parentSpanId'])->toBe($byInternal['span_graph']['spanId'])
        ->and($byInternal['span_tool']['parentSpanId'])->toBe($byInternal['span_node']['spanId'])
        ->and($byInternal['span_http']['parentSpanId'])->toBe($byInternal['span_tool']['spanId']);

    $resourceAttributes = $resourceSpan['resource']['attributes'] ?? [];
    $serviceName = collect($resourceAttributes)->firstWhere('key', 'service.name');
    expect($serviceName['value']['stringValue'] ?? null)->toBe('workflow')
        ->and(collect($resourceAttributes)->firstWhere('key', 'deployment.environment.name')['value']['stringValue'] ?? null)->toBe('testing')
        ->and(collect($resourceAttributes)->firstWhere('key', 'deployment.environment'))->toBeNull();
});

/**
 * OTLP attributes of the single span built from $span, as key => decoded value.
 *
 * @param  array<string, mixed>  $span
 * @param  list<array<string, mixed>>  $llmCalls
 * @return array<string, mixed>
 */
function otlpAttrs(array $span, array $llmCalls = []): array
{
    $payload = (new OtlpPayloadBuilder)->build(
        trace: ['id' => 'exec_map', 'graph_name' => 'g', 'status' => 'completed'],
        spans: [[
            'id' => 'span_x',
            'trace_id' => 'exec_map',
            'parent_id' => 'span_node',
            'status' => 'success',
            'status_code' => 'OK',
            'started_at' => '2026-06-27T10:00:00+00:00',
            'ended_at' => '2026-06-27T10:00:01+00:00',
            ...$span,
        ]],
        llmCalls: $llmCalls,
    );

    $attrs = [];
    foreach ($payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'] as $attribute) {
        $value = $attribute['value'];
        $attrs[$attribute['key']] = isset($value['intValue']) ? (int) $value['intValue'] : array_values($value)[0];
    }

    return $attrs;
}

it('maps promoted llm fields from the span itself to verified gen_ai attributes', function (): void {
    $attrs = otlpAttrs([
        'node_name' => 'generate llm',
        'span_type' => 'llm',
        'span_kind' => 'client',
        'model' => 'openai:gpt-4o',
        'prompt_tokens' => 10,
        'completion_tokens' => 20,
        'total_tokens' => 30,
        'cost_usd' => 0.05,
        'cached' => false,
        'streamed' => true,
    ]);

    expect($attrs)->toMatchArray([
        'gen_ai.request.model' => 'gpt-4o',
        'gen_ai.provider.name' => 'openai',
        'gen_ai.usage.input_tokens' => 10,
        'gen_ai.usage.output_tokens' => 20,
        'gen_ai.request.stream' => true,
        'agenticgraph.total_tokens' => 30,
        'agenticgraph.cost_usd' => 0.05,
        'agenticgraph.cached' => false,
    ])
        ->and($attrs)->not->toHaveKeys([
            'gen_ai.operation.name', 'gen_ai.system', 'gen_ai.prompt', 'gen_ai.completion', 'gen_ai.usage.total_tokens',
            'model', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost_usd', 'cached', 'streamed',
        ]);
});

it('maps thought_tokens to agenticgraph.thought_tokens and never leaks the raw key', function (): void {
    $attrs = otlpAttrs(['node_name' => 'n llm', 'span_type' => 'llm', 'model' => 'openai:o3', 'prompt_tokens' => 1, 'completion_tokens' => 9, 'thought_tokens' => 7]);

    expect($attrs['agenticgraph.thought_tokens'])->toBe(7)->and($attrs)->not->toHaveKey('thought_tokens');
});

it('maps the llm call type to the OTel operation and provider well-known values', function (string $model, string $callType, ?string $operation, string $provider): void {
    $attrs = otlpAttrs(
        ['node_name' => 'n llm', 'span_type' => 'llm', 'model' => $model, 'prompt_tokens' => 1, 'completion_tokens' => 1],
        [['span_id' => 'span_x', 'call_type' => $callType, 'model' => $model, 'prompt' => 'hello', 'response' => 'world']],
    );

    expect($attrs['gen_ai.operation.name'] ?? null)->toBe($operation)
        ->and($attrs['gen_ai.provider.name'])->toBe($provider)
        ->and($attrs)->not->toHaveKeys(['gen_ai.prompt', 'gen_ai.completion']);
})->with([
    'openai text' => ['openai:gpt-4o', 'text', 'chat', 'openai'],
    'anthropic structured' => ['anthropic:claude-sonnet-4-5', 'structured', 'chat', 'anthropic'],
    'gemini tools' => ['gemini:gemini-2.5-flash', 'tools', 'generate_content', 'gcp.gemini'],
    'mistral embeddings' => ['mistral:mistral-embed', 'embeddings', 'embeddings', 'mistral_ai'],
    'xai image' => ['xai:grok-2-image', 'image', null, 'x_ai'],
]);

it('leaves gen_ai request and usage attributes off a cache hit', function (): void {
    $attrs = otlpAttrs(['node_name' => 'n llm', 'span_type' => 'llm', 'model' => 'openai:gpt-4o', 'prompt_tokens' => 10, 'completion_tokens' => 5, 'cached' => true]);

    expect($attrs['agenticgraph.cached'])->toBeTrue()
        ->and(array_filter(array_keys($attrs), fn (string $key): bool => str_starts_with($key, 'gen_ai.')))->toBe([]);
});

it('maps span links to otlp links, not attributes', function (): void {
    $payload = (new OtlpPayloadBuilder)->build(
        trace: ['id' => 'exec_link', 'graph_name' => 'g', 'status' => 'running'],
        spans: [['id' => 'span_late', 'trace_id' => 'exec_link', 'parent_id' => 'span_root', 'status' => 'success', 'status_code' => 'OK',
            'started_at' => '2026-06-27T10:00:00+00:00', 'ended_at' => '2026-06-27T10:00:01+00:00',
            'links' => [['trace_id' => 'exec_link', 'span_id' => 'span_node']]]],
        llmCalls: [],
    );
    $span = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($span['links'])->toBe([['traceId' => OtlpIdEncoder::traceId('exec_link'), 'spanId' => OtlpIdEncoder::spanId('span_node')]])
        ->and(array_column($span['attributes'], 'key'))->not->toContain('links')
        ->and(array_filter(array_column($span['attributes'], 'key'), fn (string $key): bool => str_starts_with($key, 'links')))->toBe([]);
});

it('passes span attributes through without an attributes prefix', function (): void {
    $vector = otlpAttrs(['node_name' => 'vector.search', 'span_type' => 'vector', 'attributes' => ['vector.store' => 'pgvector', 'vector.top_k' => 5]]);
    $tool = otlpAttrs(['node_name' => 'lookup_order', 'span_type' => 'tool', 'attributes' => ['gen_ai.tool.call.id' => 'call_1', 'tool.type' => 'prism']]);

    expect($vector)->toMatchArray(['vector.store' => 'pgvector', 'vector.top_k' => 5])
        ->and(array_filter(array_keys($vector), fn (string $key): bool => str_starts_with($key, 'attributes.')))->toBe([])
        ->and($tool)->toMatchArray([
            'gen_ai.operation.name' => 'execute_tool',
            'gen_ai.tool.name' => 'lookup_order',
            'gen_ai.tool.call.id' => 'call_1',
            'tool.type' => 'prism',
        ]);
});

it('maps http attributes and a connection error to OTel names with an integer status', function (): void {
    $attrs = otlpAttrs([
        'node_name' => 'http_request',
        'span_type' => 'http',
        'span_kind' => 'client',
        'status' => 'failed',
        'status_code' => 'ERROR',
        'attributes' => ['http.request.method' => 'GET', 'http.response.status_code' => 503],
        'error' => ['type' => 'Illuminate\\Http\\Client\\ConnectionException', 'message' => 'refused'],
    ]);

    expect($attrs)->toMatchArray([
        'http.request.method' => 'GET',
        'http.response.status_code' => 503,
        'exception.type' => 'Illuminate\\Http\\Client\\ConnectionException',
        'exception.message' => 'refused',
    ])
        ->and($attrs)->not->toHaveKeys(['http.method', 'http.status_code', 'http.url_summary']);
});

it('resolves otlp exporter from factory when configured', function (): void {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'otlp',
        'agenticgraph.tracing.otlp.endpoint' => 'http://localhost:4318/v1/traces',
    ]);

    $exporter = TracerExporterFactory::make(app());

    expect($exporter)->toBeInstanceOf(OtlpExporter::class)
        ->and($exporter->isEnabled())->toBeTrue();
});

it('posts otlp http json payload on trace completion', function (): void {
    Http::fake([
        'localhost:4318/*' => Http::response(['partialSuccess' => (object) []], 200),
    ]);

    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'otlp',
        'agenticgraph.tracing.otlp.endpoint' => 'http://localhost:4318/v1/traces',
        'agenticgraph.tracing.otlp.headers_json' => '{"x-test":"secret"}',
    ]);

    $exporter = new OtlpExporter;
    $exporter->startTrace('exec_otlp', 'demo-graph', ['input'], new DateTimeImmutable('2026-06-27T10:00:00+00:00'));
    $exporter->addSpan([
        'id' => 'span_node',
        'trace_id' => 'exec_otlp',
        'parent_id' => null,
        'node_name' => 'generate',
        'span_type' => 'node',
        'span_kind' => 'internal',
        'status' => 'success',
        'status_code' => 'OK',
        'started_at' => '2026-06-27T10:00:00+00:00',
        'ended_at' => '2026-06-27T10:00:01+00:00',
    ]);
    $exporter->completeTrace('exec_otlp', ['output'], 1000.0);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $request->url() === 'http://localhost:4318/v1/traces'
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('x-test', 'secret')
            && isset($data['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['traceId'])
            && OtlpIdEncoder::isValidTraceId($data['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['traceId']);
    });
});

it('does not enable otlp exporter without endpoint', function (): void {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'otlp',
        'agenticgraph.tracing.otlp.endpoint' => null,
    ]);

    $exporter = new OtlpExporter;

    expect($exporter->isEnabled())->toBeFalse();
});

it('keeps microsecond precision in otlp unix nanos', function (): void {
    $payload = (new OtlpPayloadBuilder)->build(
        trace: ['id' => 'exec_nanos', 'graph_name' => 'demo-graph', 'status' => 'completed'],
        spans: [[
            'id' => 'span_node',
            'trace_id' => 'exec_nanos',
            'parent_id' => null,
            'node_name' => 'generate',
            'span_type' => 'node',
            'status' => 'success',
            'status_code' => 'OK',
            'started_at' => '2026-06-27T10:00:00.123456+00:00',
            'ended_at' => '2026-06-27T10:00:01.654321+00:00',
            'duration_ms' => 1530.865,
        ]],
    );

    $span = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($span['startTimeUnixNano'])->toBe('1782554400123456000')
        ->and($span['endTimeUnixNano'])->toBe('1782554401654321000')
        ->and((int) $span['startTimeUnixNano'] % 1_000_000_000)->toBe(123_456_000);
});

it('maps a paused interrupted span to otlp unset status', function (): void {
    $payload = (new OtlpPayloadBuilder)->build(
        trace: ['id' => 'exec_paused', 'graph_name' => 'demo-graph', 'status' => 'interrupted'],
        spans: [[
            'id' => 'span_graph',
            'trace_id' => 'exec_paused',
            'parent_id' => null,
            'node_name' => 'demo-graph',
            'span_type' => 'graph',
            'status' => 'interrupted',
            'status_code' => 'PAUSED',
            'started_at' => '2026-06-27T10:00:00.000000+00:00',
            'ended_at' => '2026-06-27T10:00:01.000000+00:00',
            'duration_ms' => 1000,
        ]],
    );

    expect($payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['status'])->toBe(['code' => 0]);
});

/**
 * @return list<array<string, mixed>> the OTLP spans of every export, in order, tagged with the export index
 */
function otlpExportedSpans(): array
{
    $spans = [];
    foreach (Http::recorded() as $index => [$request]) {
        foreach ($request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [] as $span) {
            $spans[] = $span + ['export' => $index];
        }
    }

    return $spans;
}

it('exports the resumed segment of an interrupted trace, in the same process and in a new one', function (bool $newProcess): void {
    Http::fake(['localhost:4318/*' => Http::response([], 200)]);
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.otlp.endpoint' => 'http://localhost:4318/v1/traces',
    ]);

    $exporter = new OtlpExporter;
    $exporter->startTrace('exec_f7', 'demo-graph');
    $exporter->addSpan(['id' => 'span_before', 'trace_id' => 'exec_f7', 'parent_id' => 'span_root', 'span_type' => 'node', 'node_name' => 'before']);
    $exporter->interruptTrace('exec_f7', 'approval');

    $resumed = $newProcess ? new OtlpExporter : $exporter;
    $resumed->resumeTrace('exec_f7', 'demo-graph');
    $resumed->addSpan(['id' => 'span_after', 'trace_id' => 'exec_f7', 'parent_id' => 'span_root', 'span_type' => 'node', 'node_name' => 'after']);
    $resumed->completeTrace('exec_f7', [], 10.0);

    $names = array_map(fn (array $span): string => $span['export'].':'.$span['name'], otlpExportedSpans());

    Http::assertSentCount(2);
    expect($names)->toBe(['0:before', '1:after']);
})->with(['same process' => false, 'new process' => true]);

it('exports exactly one root span for an interrupted then resumed trace, in the final segment', function (): void {
    Http::fake(['localhost:4318/*' => Http::response([], 200)]);
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.otlp.endpoint' => 'http://localhost:4318/v1/traces',
    ]);
    $root = ['id' => 'span_root', 'trace_id' => 'exec_i6', 'parent_id' => null, 'span_type' => 'graph', 'node_name' => 'demo-graph'];

    $exporter = new OtlpExporter;
    $exporter->startTrace('exec_i6', 'demo-graph');
    $exporter->addSpan(['id' => 'span_node', 'trace_id' => 'exec_i6', 'parent_id' => 'span_root', 'span_type' => 'node', 'node_name' => 'node']);
    $exporter->addSpan($root + ['status' => 'interrupted', 'status_code' => 'PAUSED']);
    $exporter->interruptTrace('exec_i6', 'approval');

    $resumed = new OtlpExporter;
    $resumed->resumeTrace('exec_i6', 'demo-graph');
    $resumed->addSpan($root + ['status' => 'success', 'status_code' => 'OK']);
    $resumed->completeTrace('exec_i6', [], 10.0);

    $roots = array_values(array_filter(
        otlpExportedSpans(),
        fn (array $span): bool => $span['spanId'] === OtlpIdEncoder::spanId('span_root')
    ));

    expect($roots)->toHaveCount(1)
        ->and($roots[0]['export'])->toBe(1)
        ->and($roots[0]['status']['code'] ?? null)->toBe(1);
});

it('maps cache tokens to the pinned gen_ai attributes and sends input_tokens including them', function (): void {
    $attrs = otlpAttrs(['node_name' => 'n llm', 'span_type' => 'llm', 'model' => 'openai:gpt-4o', 'prompt_tokens' => 10, 'completion_tokens' => 4, 'cache_read_tokens' => 80, 'cache_write_tokens' => 5]);

    expect($attrs['gen_ai.usage.input_tokens'])->toBe(95)
        ->and($attrs['gen_ai.usage.output_tokens'])->toBe(4)
        ->and($attrs['gen_ai.usage.cache_read.input_tokens'])->toBe(80)
        ->and($attrs['gen_ai.usage.cache_write.input_tokens'])->toBe(5)
        ->and($attrs['agenticgraph.cache_read_tokens'])->toBe(80)
        ->and($attrs['agenticgraph.cache_write_tokens'])->toBe(5)
        ->and($attrs)->not->toHaveKeys(['cache_read_tokens', 'cache_write_tokens']);
});

it('sends no cache attributes and the plain prompt count when the provider reported none', function (): void {
    $attrs = otlpAttrs(['node_name' => 'n llm', 'span_type' => 'llm', 'model' => 'openai:gpt-4o', 'prompt_tokens' => 10, 'completion_tokens' => 4]);

    expect($attrs['gen_ai.usage.input_tokens'])->toBe(10)
        ->and($attrs)->not->toHaveKeys(['gen_ai.usage.cache_read.input_tokens', 'gen_ai.usage.cache_write.input_tokens', 'agenticgraph.cache_read_tokens']);
});
