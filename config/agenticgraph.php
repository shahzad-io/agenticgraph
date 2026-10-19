<?php

use Shahzad\AgenticGraph\Middleware\LoggingMiddleware;
use Shahzad\AgenticGraph\Middleware\TimingMiddleware;
use Shahzad\AgenticGraph\Middleware\ValidationMiddleware;

return [
    'storage' => env('AGENTICGRAPH_STORAGE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Graph Registry Configuration
    |--------------------------------------------------------------------------
    |
    | Register your graph classes here for auto-discovery and building.
    | This enables automatic graph rebuilding when resuming from checkpoints
    | or executing async workflows without manual registration.
    |
    | Format: 'graph-name' => GraphClass::class
    |
    | Example:
    | 'graphs' => [
    |     'content-moderation' => App\Agents\ContentModeration\Graph::class,
    |     'currency-conversion' => App\Agents\CurrencyConversion\Graph::class,
    | ],
    |
    */
    'graphs' => [
        // Add your graph classes here
    ],

    'checkpoint' => [
        'enabled' => env('AGENTICGRAPH_CHECKPOINT_ENABLED', true),
        'ttl' => env('AGENTICGRAPH_CHECKPOINT_TTL', 7),
        'frequency' => env('AGENTICGRAPH_CHECKPOINT_FREQUENCY', 1), // Create checkpoint every N nodes (1 = every node)
        'max_state_size' => env('AGENTICGRAPH_MAX_STATE_SIZE', 10485760), // 10MB in bytes
    ],

    'interrupts' => [
        'persistence_enabled' => env('AGENTICGRAPH_INTERRUPT_PERSISTENCE', true),
        'default_expiry_days' => env('AGENTICGRAPH_INTERRUPT_EXPIRY_DAYS', 7),
        'broadcast_enabled' => env('AGENTICGRAPH_INTERRUPT_BROADCAST', true),
        'handler' => env('AGENTICGRAPH_INTERRUPT_HANDLER'),
    ],

    'execution' => [
        'queue_connection' => env('AGENTICGRAPH_QUEUE_CONNECTION', 'redis'),
        'queue_name' => env('AGENTICGRAPH_QUEUE_NAME', 'agenticgraph'),
        'tries' => env('AGENTICGRAPH_EXECUTION_TRIES', 1), // ExecuteGraphJob attempts
        'timeout' => env('AGENTICGRAPH_EXECUTION_TIMEOUT', 0), // ExecuteGraphJob seconds; 0 = worker default
        'resume_tries' => env('AGENTICGRAPH_RESUME_TRIES', 1), // ResumeExecutionJob attempts
        'resume_timeout' => env('AGENTICGRAPH_RESUME_TIMEOUT', 0), // ResumeExecutionJob seconds; 0 = worker default
        'max_iterations' => env('AGENTICGRAPH_MAX_ITERATIONS', 1000), // existing - used when loop.enabled = false

        // Loop support configuration (opt-in, backward compatible)
        'loop' => [
            'enabled' => env('AGENTICGRAPH_LOOP_ENABLED', false),
            'max_iterations' => env('AGENTICGRAPH_LOOP_MAX_ITERATIONS', 1000),
            'max_cycle_iterations' => env('AGENTICGRAPH_LOOP_MAX_CYCLE_ITERATIONS', null),
            'timeout_seconds' => env('AGENTICGRAPH_LOOP_TIMEOUT_SECONDS', null),
            'cycle_detection' => env('AGENTICGRAPH_LOOP_CYCLE_DETECTION', 'silent'), // silent|warn|error
        ],
    ],

    'lock' => [
        'timeout' => env('AGENTICGRAPH_LOCK_TIMEOUT', 10),
        'block_timeout' => env('AGENTICGRAPH_LOCK_BLOCK_TIMEOUT', 5),
    ],

    'router' => [
        'max_retries' => env('AGENTICGRAPH_ROUTER_MAX_RETRIES', 3),
        'retry_delay_ms' => env('AGENTICGRAPH_ROUTER_RETRY_DELAY_MS', 100),
    ],

    'cache' => [
        'version' => env('AGENTICGRAPH_CACHE_VERSION', 'v1'),
    ],

    'logging' => [
        'enabled' => env('AGENTICGRAPH_LOGGING', true),
    ],

    'prism' => [
        'model' => env('AGENTICGRAPH_PRISM_MODEL'), // required, provider:model (example: openai:gpt-4o-mini); no default
        'cache_enabled' => env('AGENTICGRAPH_PRISM_CACHE', false),
        'cache_ttl' => env('AGENTICGRAPH_PRISM_CACHE_TTL', 60),
        'max_tokens' => env('AGENTICGRAPH_PRISM_MAX_TOKENS', null), // max output tokens per PrismNode call; null = no limit sent
        // Extra model calls when a structured reply does not decode or fit as(); the error is fed back. 0 = fail at once.
        'structured' => [
            'retries' => env('AGENTICGRAPH_PRISM_STRUCTURED_RETRIES', 1),
        ],
        'reasoning' => [
            // Token budgets for a reasoning level on providers that take a budget (Anthropic); no package defaults.
            'budgets' => [
                'low' => env('AGENTICGRAPH_PRISM_REASONING_BUDGET_LOW'),
                'medium' => env('AGENTICGRAPH_PRISM_REASONING_BUDGET_MEDIUM'),
                'high' => env('AGENTICGRAPH_PRISM_REASONING_BUDGET_HIGH'),
            ],
            // What level 'off' sends, per provider, for providers where it is a model-specific value (openai: none, minimal or off); no package default.
            'off' => [
                'openai' => env('AGENTICGRAPH_PRISM_REASONING_OFF_OPENAI'),
            ],
        ],
        'embeddings' => [
            'provider' => env('AGENTICGRAPH_PRISM_EMBEDDINGS_PROVIDER'),
            'model' => env('AGENTICGRAPH_PRISM_EMBEDDINGS_MODEL'),
            'api_key' => env('AGENTICGRAPH_PRISM_EMBEDDINGS_API_KEY'),
        ],
    ],

    'middleware' => [
        'global' => [
            LoggingMiddleware::class,
            TimingMiddleware::class,
            ValidationMiddleware::class,
        ],
        'cache_ttl' => env('AGENTICGRAPH_MIDDLEWARE_CACHE_TTL', 3600),
    ],

    'circuit_breaker' => [
        'failure_threshold' => env('AGENTICGRAPH_CIRCUIT_BREAKER_THRESHOLD', 5),
        'timeout' => env('AGENTICGRAPH_CIRCUIT_BREAKER_TIMEOUT', 60),
        'retry_after' => env('AGENTICGRAPH_CIRCUIT_BREAKER_RETRY_AFTER', 300),
        'half_open_requests' => env('AGENTICGRAPH_CIRCUIT_BREAKER_HALF_OPEN', 3), // Requests to test in half-open state
    ],

    'streaming' => [
        'sse' => [
            'enabled' => env('AGENTICGRAPH_STREAM_SSE_ENABLED', true),
            'max_duration_seconds' => env('AGENTICGRAPH_STREAM_SSE_MAX_DURATION', 120),
            'poll_interval_ms' => env('AGENTICGRAPH_STREAM_SSE_POLL_INTERVAL', 250),
            'use_blocking_reads' => env('AGENTICGRAPH_STREAM_SSE_BLOCKING_READS', true),
            'heartbeat_interval' => env('AGENTICGRAPH_STREAM_SSE_HEARTBEAT', 30),
            // SSE `retry:` field and the planned-close `after_ms`.
            'retry_ms' => env('AGENTICGRAPH_STREAM_SSE_RETRY_MS', 2000),
        ],
        'broadcast' => [
            'channel_prefix' => env('AGENTICGRAPH_STREAM_BROADCAST_CHANNEL_PREFIX', 'agenticgraph.stream.'),
            'force_above_seconds' => env('AGENTICGRAPH_STREAM_BROADCAST_FORCE_ABOVE', 300),
        ],
        'store' => [
            'default_ttl_seconds' => env('AGENTICGRAPH_STREAM_DEFAULT_TTL', 3600),
            'extended_ttl_seconds' => env('AGENTICGRAPH_STREAM_EXTENDED_TTL', 86400),
            'extend_threshold_seconds' => env('AGENTICGRAPH_STREAM_EXTEND_THRESHOLD', 300),
            'driver' => env('STREAM_STORE_DRIVER', 'redis'),
            'redis' => [
                'connection' => env('STREAM_STORE_REDIS_CONNECTION', 'default'),
                'ttl' => env('STREAM_STORE_TTL', 86400),
                'max_len' => env('STREAM_STORE_MAX_LEN', 10000),
            ],
            'database' => [
                'table' => 'stream_events',
                'connection' => env('STREAM_STORE_DB_CONNECTION'),
            ],
        ],
        'rate_limit' => [
            'enabled' => env('AGENTICGRAPH_STREAM_RATE_LIMIT_ENABLED', false),
            'max_events_per_second' => env('AGENTICGRAPH_STREAM_RATE_LIMIT_MAX', 100),
        ],
        'retention' => [
            'max_age_hours' => env('AGENTICGRAPH_STREAM_RETENTION_AGE_HOURS', 24),
            'max_events_per_stream' => env('AGENTICGRAPH_STREAM_RETENTION_MAX_EVENTS', 10000),
        ],
        'errors' => [
            'store_fatal' => env('AGENTICGRAPH_STREAM_STORE_FATAL', false),
            'transport_fatal' => env('AGENTICGRAPH_STREAM_TRANSPORT_FATAL', true),
        ],
        'routes' => [
            'enabled' => env('AGENTICGRAPH_STREAMING_ROUTES', true),
            'prefix' => env('AGENTICGRAPH_STREAMING_PREFIX', 'agentic-graph/streams'),
            // AGENTICGRAPH_STREAMING_MIDDLEWARE: comma-separated, e.g. "api,throttle:60,1". A comma before a
            // number stays with the previous middleware's parameters; use a middleware group for other parameters.
            'middleware' => array_values(array_filter(array_map('trim', preg_split('/,(?!\s*\d+\s*(?:,|$))/', (string) env('AGENTICGRAPH_STREAMING_MIDDLEWARE', 'api')) ?: []), fn (string $middleware) => $middleware !== '')) ?: ['api'],
        ],
        'capture' => [
            'state' => env('AGENTICGRAPH_STREAM_CAPTURE_STATE', 'none'),
            // 'none' = lifecycle payload unchanged (default for stream store)
            // 'diff' = attach input→output state diff on node_complete
            // 'full' = attach full output state (toStoragePayload shape) on node_complete
        ],
        // final_state in execution_terminal, stream_close meta and stream_replay_unavailable.
        // expose=false sends null. keys = list of top-level state keys to send, null = all.
        // AGENTICGRAPH_STREAM_FINAL_STATE_KEYS: comma-separated, e.g. "response,status"; empty = null.
        'final_state' => [
            'expose' => env('AGENTICGRAPH_STREAM_EXPOSE_FINAL_STATE', false),
            'keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('AGENTICGRAPH_STREAM_FINAL_STATE_KEYS', ''))), fn (string $key) => $key !== '')) ?: null,
        ],
    ],

    'api' => [
        'timeout' => env('AGENTICGRAPH_API_TIMEOUT', 30), // seconds
        'connect_timeout' => env('AGENTICGRAPH_API_CONNECT_TIMEOUT', 10), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing Configuration (AgenticPulse)
    |--------------------------------------------------------------------------
    |
    | Configure tracing for observability with AgenticPulse SaaS.
    | Get your API key at https://agenticpulse.io
    |
    */
    'tracing' => [
        'enabled' => env('AGENTICGRAPH_TRACING', false),

        // Exporter driver: auto, pulse, log, file, null, otlp. auto is null in unit tests,
        // pulse when api_key is set, otherwise log.
        'exporter' => env('AGENTICGRAPH_TRACING_EXPORTER', 'auto'),

        // exporter=file writes {path}/{trace_id}/{Ymd-His-u}.json, one file per segment
        'file' => [
            'path' => env('AGENTICGRAPH_TRACING_FILE_PATH', storage_path('app/agenticgraph/traces')),
        ],

        // AgenticPulse API credentials (required only for exporter=pulse or auto with key)
        'api_key' => env('AGENTICPULSE_API_KEY'),
        'endpoint' => env('AGENTICPULSE_ENDPOINT', 'https://ingest.agenticgraph.dev'),

        // Ingest transport: seconds per attempt; one retry on a connection error or 5xx
        'transport' => [
            'connect_timeout' => env('AGENTICGRAPH_TRACING_CONNECT_TIMEOUT', 1),
            'timeout' => env('AGENTICGRAPH_TRACING_TIMEOUT', 3),
        ],

        'service' => [
            'name' => env('AGENTICGRAPH_SERVICE_NAME', 'agenticgraph'),
            'version' => env('AGENTICGRAPH_SERVICE_VERSION', 'unknown'),
        ],
        'environment' => env('APP_ENV', 'local'),
        'instrumentation_scope' => 'agenticgraph',

        // What to capture
        'capture' => [
            'state' => env('AGENTICGRAPH_CAPTURE_STATE', 'diff'),
            // 'diff' = only keys that changed (minimal, default)
            // 'full' = complete state snapshots
            // 'error_only' = full on error, keys on success

            'prompts' => env('AGENTICGRAPH_CAPTURE_PROMPTS', false),
            'responses' => env('AGENTICGRAPH_CAPTURE_RESPONSES', false),
            'max_length' => env('AGENTICGRAPH_CAPTURE_MAX_LENGTH', 2000),

            // Broad HTTP/DB auto-capture (default off). Tool-internal HTTP/DB still nests
            // under tool spans via the tool wrap without enabling these flags.
            'http' => env('AGENTICGRAPH_CAPTURE_HTTP', false),
            'db' => env('AGENTICGRAPH_CAPTURE_DB', false),

            // Capture depth when HTTP/DB/tool spans are emitted (PII-safe defaults).
            'tool_args' => env('AGENTICGRAPH_CAPTURE_TOOL_ARGS', 'summary'), // none|summary|full
            'tool_results' => env('AGENTICGRAPH_CAPTURE_TOOL_RESULTS', 'none'), // none|summary|full
            'http_urls' => env('AGENTICGRAPH_CAPTURE_HTTP_URLS', 'summary'), // none|summary|full
            'db_sql' => env('AGENTICGRAPH_CAPTURE_DB_SQL', 'summary'), // none|summary|full
        ],

        'db' => [
            'slow_threshold_ms' => env('AGENTICGRAPH_DB_SLOW_THRESHOLD_MS', 1000),
        ],

        // OTLP exporter (OSS reach: Jaeger, Tempo, Honeycomb, Datadog, etc.)
        // Set AGENTICGRAPH_TRACING_EXPORTER=otlp and point at your collector.
        'otlp' => [
            'endpoint' => env('AGENTICGRAPH_OTLP_ENDPOINT', 'http://localhost:4318/v1/traces'),
            'protocol' => env('AGENTICGRAPH_OTLP_PROTOCOL', 'http/json'), // http/json only for now
            'headers' => array_filter([
                'x-honeycomb-team' => env('AGENTICGRAPH_OTLP_HONEYCOMB_API_KEY'),
                'dd-api-key' => env('AGENTICGRAPH_OTLP_DATADOG_API_KEY'),
            ]),
            // Additional headers as JSON object, e.g. {"Authorization":"Bearer token"}
            'headers_json' => env('AGENTICGRAPH_OTLP_HEADERS_JSON'),
        ],

        // Sampling configuration
        'sampling' => [
            'rate' => env('AGENTICGRAPH_SAMPLE_RATE', 1.0),
            'always_on_error' => true,
        ],
        'http' => [
            'request_ttl_seconds' => env('AGENTICGRAPH_HTTP_REQUEST_TTL', 120),
        ],
    ],
];
