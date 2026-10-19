<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Tracing\Otlp\OtlpPayloadBuilder;

/**
 * Span attribute names in OTel namespaces, each verified against a pinned semconv release
 * (spec M-3, M-4; the table is in spec/tracing-wire-v1.md). Adding a name here means
 * verifying it first.
 */
const VERIFIED_OTEL_ATTRIBUTES = [
    'db.operation.name',
    'db.query.text',
    'http.request.method',
    'http.response.status_code',
    'url.full',
    'exception.type',
    'exception.message',
    'gen_ai.operation.name',
    'gen_ai.provider.name',
    'gen_ai.request.model',
    'gen_ai.request.stream',
    'gen_ai.usage.input_tokens',
    'gen_ai.usage.output_tokens',
    'gen_ai.usage.cache_read.input_tokens',
    'gen_ai.usage.cache_write.input_tokens',
    'gen_ai.tool.name',
    'gen_ai.tool.call.id',
];

/** Resource attribute names, verified the same way (deployment.environment is deprecated, spec N-2). */
const VERIFIED_OTEL_RESOURCE_ATTRIBUTES = [
    'service.name',
    'service.version',
    'deployment.environment.name',
];

const OTEL_SPAN_NAMESPACES = '(db|http|url|server|exception|gen_ai|service|deployment)';

it('uses no OTel-namespace attribute name in src that is not in the verified table', function (): void {
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src'));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        preg_match_all('/[\'"]('.OTEL_SPAN_NAMESPACES.'\.[a-z_.]+)[\'"]/', (string) file_get_contents($file->getPathname()), $matches);
        $found = [...$found, ...$matches[1]];
    }

    // 'db.query' is the db span's node_name, not an attribute.
    $unverified = array_diff(array_unique($found), VERIFIED_OTEL_ATTRIBUTES, VERIFIED_OTEL_RESOURCE_ATTRIBUTES, ['db.query']);

    expect(array_values($unverified))->toBe([]);
});

it('emits no unverified OTel-namespace key in the OTLP payload', function (): void {
    $base = ['trace_id' => 'exec_guard', 'status' => 'failed', 'status_code' => 'ERROR', 'started_at' => '2026-09-27T10:00:00+00:00', 'ended_at' => '2026-09-27T10:00:01+00:00'];
    $payload = (new OtlpPayloadBuilder)->build(
        trace: ['id' => 'exec_guard', 'graph_name' => 'g', 'status' => 'failed'],
        spans: [
            [...$base, 'id' => 's1', 'node_name' => 'n llm', 'span_type' => 'llm', 'model' => 'openai:gpt-4o', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2, 'cost_usd' => 0.1, 'cached' => false, 'streamed' => false],
            [...$base, 'id' => 's2', 'node_name' => 'lookup', 'span_type' => 'tool', 'attributes' => ['gen_ai.tool.call.id' => 'c1', 'tool.type' => 'prism'], 'error' => ['type' => 'E', 'message' => 'm']],
            [...$base, 'id' => 's3', 'node_name' => 'http_request', 'span_type' => 'http', 'attributes' => ['http.request.method' => 'GET', 'http.response.status_code' => 500, 'laravel.http.url_summary' => 'https://x.test/a']],
            [...$base, 'id' => 's4', 'node_name' => 'db.query', 'span_type' => 'db', 'attributes' => ['laravel.db.connection' => 'mysql', 'db.operation.name' => 'select', 'laravel.db.query_summary' => 'SELECT users']],
        ],
        llmCalls: [['span_id' => 's1', 'call_type' => 'text', 'model' => 'openai:gpt-4o', 'prompt' => 'p', 'response' => 'r']],
    );

    $resourceKeys = array_column($payload['resourceSpans'][0]['resource']['attributes'], 'key');
    $otelResourceKeys = array_filter($resourceKeys, fn (string $key): bool => preg_match('/^'.OTEL_SPAN_NAMESPACES.'\./', $key) === 1);

    expect(array_values($otelResourceKeys))->toBe(VERIFIED_OTEL_RESOURCE_ATTRIBUTES);

    $keys = [];
    foreach ($payload['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
        $keys = [...$keys, ...array_column($span['attributes'], 'key')];
    }
    $otelKeys = array_filter(array_unique($keys), fn (string $key): bool => preg_match('/^'.OTEL_SPAN_NAMESPACES.'\./', $key) === 1);

    expect(array_values(array_diff($otelKeys, VERIFIED_OTEL_ATTRIBUTES)))->toBe([])
        ->and($otelKeys)->toContain('exception.type', 'gen_ai.usage.input_tokens', 'http.response.status_code', 'db.operation.name');
});
