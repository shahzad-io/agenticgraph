<?php

declare(strict_types=1);

/*
 * Trace wire key registry (agenticgraph.trace.v1.1). TraceWireContractFreezeTest checks it against what the code
 * emits, both directions. Keys are only added, never renamed or removed. The same registry is kept with the private
 * AgenticPulse contract; a change here is made there too.
 */

return [
    'schema_version' => 'agenticgraph.trace.v1.1',
    'envelope' => [
        'keys' => ['schema_version', 'trace', 'segment', 'spans', 'llm_calls'],
        'optional' => ['_meta'],
    ],
    'trace' => [
        'always' => ['id', 'status', 'sampled', 'resource', 'instrumentation_scope'],
        'optional' => ['graph_name', 'started_at', 'initial_state_keys', 'initial_state', 'interrupt_reason', 'interrupted_at', 'resumed_at', 'ended_at', 'duration_ms', 'active_ms', 'final_state_keys', 'final_state', 'error'],
        'status' => ['running', 'interrupted', 'completed', 'failed'],
        'resource' => ['service.name', 'service.version', 'deployment.environment.name'],
    ],
    'segment' => [
        'keys' => ['id', 'kind', 'final', 'started_at', 'ended_at'],
        'kind' => ['initial', 'resumed', 'continued'],
        'id_pattern' => '^seg_[0-9a-f]{16}$',
    ],
    'span' => [
        'common' => ['id', 'trace_id', 'parent_id', 'node_name', 'node_type', 'span_type', 'status', 'span_kind', 'status_code', 'started_at', 'ended_at', 'duration_ms'],
        'optional' => ['links'],
        'status' => ['success', 'failed', 'interrupted', 'resumed', 'slow'],
        'status_code' => ['OK', 'ERROR', 'PAUSED'],
        'span_kind' => ['internal', 'client'],
        'timestamp_pattern' => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{6}\\+00:00$',
    ],
    'span_types' => [
        'graph' => [
            'node_type' => 'graph',
            'top' => ['error'],
            'attributes' => [],
        ],
        'node' => [
            'node_type' => null,
            'top' => ['attempt', 'attempt_of', 'state_diff', 'loop_context', 'error'],
            'attributes' => [],
        ],
        'llm' => [
            'node_type' => 'llm',
            'top' => ['model', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost_usd', 'cached', 'streamed', 'thought_tokens', 'cache_read_tokens', 'cache_write_tokens', 'error'],
            'attributes' => ['context.count', 'context.ids', 'prompt.name', 'prompt.version', 'prompt.hash'],
        ],
        'route' => [
            'node_type' => 'route',
            'top' => [],
            'attributes' => ['route.from', 'route.targets', 'route.router', 'route.kind', 'route.from_cache', 'route.fallback', 'route.reason'],
        ],
        'tool' => [
            'node_type' => 'tool',
            'top' => ['error'],
            'attributes' => ['tool.type', 'tool.provider', 'tool.input_size', 'tool.output_size', 'tool.request_id', 'gen_ai.tool.call.id', 'tool.arg_keys', 'tool.args', 'tool.result_size', 'tool.result'],
        ],
        'db' => [
            'node_type' => 'db',
            'top' => [],
            'attributes' => ['laravel.db.connection', 'laravel.db.statement_type', 'db.operation.name', 'laravel.db.bindings_count', 'laravel.db.query_summary', 'db.query.text'],
        ],
        'http' => [
            'node_type' => 'http',
            'top' => ['error'],
            'attributes' => ['http.request.method', 'http.response.status_code', 'laravel.http.response_size', 'laravel.http.url_summary', 'url.full'],
        ],
        'vector' => [
            'node_type' => 'vector',
            'top' => ['error'],
            'attributes_prefix' => 'vector.',
        ],
        'interrupt' => [
            'node_type' => 'interrupt',
            'top' => ['state_snapshot'],
            'attributes' => ['interrupt.reason', 'checkpoint.id'],
        ],
        'resume' => [
            'node_type' => 'resume',
            'top' => ['state_snapshot'],
            'attributes' => ['checkpoint.id'],
        ],
        'custom' => [
            'node_type' => null,
            'top' => ['error'],
            'attributes_open' => true,
        ],
    ],
    'route_kind' => ['llm', 'deterministic', 'fan_out', 'join'],
    'llm_calls' => [
        'always' => ['id', 'trace_id', 'span_id', 'node', 'call_type', 'model', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'duration_ms', 'created_at', 'cost_usd', 'streamed'],
        'optional' => ['prompt', 'response', 'cached', 'cache_key', 'thought_tokens', 'cache_read_tokens', 'cache_write_tokens', 'prompt_name', 'prompt_version', 'prompt_hash'],
        'call_type' => ['text', 'structured', 'tools', 'embeddings', 'image', 'audio', 'mcp', 'cached'],
    ],
    '_meta' => [
        'truncated' => ['truncated', 'truncation_applied', 'original_size_bytes', 'dropped', 'truncated_at'],
        'payload_too_large' => ['truncated', 'truncation_applied', 'error_reason', 'original_size_bytes', 'dropped', 'truncated_at'],
        'dropped' => ['spans', 'llm_calls'],
        'truncation_applied' => ['responses', 'prompts', 'spans', 'state_values', 'complete_payload'],
        'error_reason' => ['payload_too_large'],
    ],
    'merged' => [
        'keys' => ['schema_version', 'trace', 'segments', 'spans', 'llm_calls'],
        'optional' => ['_meta'],
        'segments' => [
            'keys' => ['id', 'kind', 'final', 'started_at', 'ended_at'],
            'optional' => ['interrupt', '_meta'],
        ],
        '_meta' => [
            'always' => ['truncated', 'truncation_applied'],
            'optional' => ['error_reason', 'dropped'],
        ],
    ],
];
