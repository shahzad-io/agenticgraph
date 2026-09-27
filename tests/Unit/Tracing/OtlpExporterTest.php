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
                'deployment.environment' => 'testing',
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
    expect($serviceName['value']['stringValue'] ?? null)->toBe('workflow');
});

it('merges llm call gen_ai attributes into matching llm spans', function (): void {
    $builder = new OtlpPayloadBuilder;

    $payload = $builder->build(
        trace: ['id' => 'exec_llm', 'graph_name' => 'g', 'status' => 'completed'],
        spans: [[
            'id' => 'span_llm',
            'trace_id' => 'exec_llm',
            'parent_id' => 'span_node',
            'node_name' => 'generate llm',
            'span_type' => 'llm',
            'span_kind' => 'client',
            'status' => 'success',
            'status_code' => 'OK',
            'started_at' => '2026-06-27T10:00:00+00:00',
            'ended_at' => '2026-06-27T10:00:01+00:00',
            'model' => 'openai:gpt-4o',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'cost_usd' => 0.05,
        ]],
        llmCalls: [[
            'span_id' => 'span_llm',
            'trace_id' => 'exec_llm',
            'call_type' => 'text',
            'model' => 'openai:gpt-4o',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'cost_usd' => 0.05,
            'prompt' => 'hello',
            'response' => 'world',
        ]],
    );

    $span = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
    $keys = array_column($span['attributes'], 'key');

    expect($keys)->toContain('gen_ai.request.model')
        ->and($keys)->toContain('gen_ai.usage.input_tokens')
        ->and($keys)->toContain('gen_ai.usage.output_tokens')
        ->and($keys)->toContain('agenticgraph.cost_usd')
        ->and($keys)->toContain('gen_ai.prompt')
        ->and($keys)->toContain('gen_ai.completion');
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
