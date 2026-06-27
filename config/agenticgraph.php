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
        'auto_cleanup_days' => env('AGENTICGRAPH_INTERRUPT_CLEANUP_DAYS', 30),
        'broadcast_enabled' => env('AGENTICGRAPH_INTERRUPT_BROADCAST', true),
        'handler' => env('AGENTICGRAPH_INTERRUPT_HANDLER'),
    ],

    'execution' => [
        'queue_connection' => env('AGENTICGRAPH_QUEUE_CONNECTION', 'redis'),
        'queue_name' => env('AGENTICGRAPH_QUEUE_NAME', 'agenticgraph'),
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
        'model' => env('AGENTICGRAPH_PRISM_MODEL', 'anthropic:claude-sonnet-4'),
        'cache_enabled' => env('AGENTICGRAPH_PRISM_CACHE', true),
        'cache_ttl' => env('AGENTICGRAPH_PRISM_CACHE_TTL', 60),
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
            'middleware' => ['api'],
        ],
        'capture' => [
            'state' => env('AGENTICGRAPH_STREAM_CAPTURE_STATE', 'none'),
            // 'none' = lifecycle payload unchanged (default for stream store)
            // 'diff' = attach input→output state diff on node_complete
            // 'full' = attach full output state (toStoragePayload shape) on node_complete
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

        // Exporter driver: auto (pulse when api_key set, else log), pulse, log, null
        'exporter' => env('AGENTICGRAPH_TRACING_EXPORTER', 'auto'),

        // AgenticPulse API credentials (required only for exporter=pulse or auto with key)
        'api_key' => env('AGENTICPULSE_API_KEY'),
        'endpoint' => env('AGENTICPULSE_ENDPOINT', 'https://api.agenticpulse.io'),

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
        ],

        // Sampling configuration
        'sampling' => [
            'rate' => env('AGENTICGRAPH_SAMPLE_RATE', 1.0),
            'always_on_error' => true,
        ],
        // Trace spans are buffered in-memory for the duration of a single execution
        // and flushed once on completion. These keys are retained for compatibility only.
        'buffer' => [
            'store' => env('AGENTICGRAPH_TRACING_BUFFER_STORE'),
            'ttl_seconds' => env('AGENTICGRAPH_TRACING_BUFFER_TTL', 3600),
        ],
        'http' => [
            'request_ttl_seconds' => env('AGENTICGRAPH_HTTP_REQUEST_TTL', 120),
        ],
    ],
];
