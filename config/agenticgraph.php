<?php

return [
    'storage' => env('AGENTICGRAPH_STORAGE', 'database'),

    'checkpoint' => [
        'enabled' => env('AGENTICGRAPH_CHECKPOINT_ENABLED', true),
        'strategy' => env('AGENTICGRAPH_CHECKPOINT_STRATEGY', 'every_node'),
        'ttl' => env('AGENTICGRAPH_CHECKPOINT_TTL', 7),
        'compression' => env('AGENTICGRAPH_CHECKPOINT_COMPRESSION', true),
        'encryption' => env('AGENTICGRAPH_CHECKPOINT_ENCRYPTION', false),
    ],

    'execution' => [
        'timeout' => env('AGENTICGRAPH_TIMEOUT', 300),
        'max_retries' => env('AGENTICGRAPH_MAX_RETRIES', 3),
        'retry_delay' => env('AGENTICGRAPH_RETRY_DELAY', 1000),
        'parallel' => env('AGENTICGRAPH_PARALLEL', true),
        'queue_connection' => env('AGENTICGRAPH_QUEUE_CONNECTION', 'redis'),
        'queue_name' => env('AGENTICGRAPH_QUEUE_NAME', 'agenticgraph'),
        'max_iterations' => env('AGENTICGRAPH_MAX_ITERATIONS', 1000),
    ],

    'lock' => [
        'driver' => env('AGENTICGRAPH_LOCK_DRIVER', 'redis'),
        'timeout' => env('AGENTICGRAPH_LOCK_TIMEOUT', 10),
        'prefix' => env('AGENTICGRAPH_LOCK_PREFIX', 'agenticgraph'),
        'strategy' => env('AGENTICGRAPH_LOCK_STRATEGY', 'execution'),
        'retry_interval' => env('AGENTICGRAPH_LOCK_RETRY_INTERVAL', 100),
    ],

    'prism' => [
        'model' => env('AGENTICGRAPH_PRISM_MODEL', 'anthropic:claude-sonnet-4'),
        'cache_enabled' => env('AGENTICGRAPH_PRISM_CACHE', true),
        'cache_ttl' => env('AGENTICGRAPH_PRISM_CACHE_TTL', 60),
        'track_tokens' => env('AGENTICGRAPH_PRISM_TRACK_TOKENS', true),
        'timeout' => env('AGENTICGRAPH_PRISM_TIMEOUT', 120),
    ],

    'events' => [
        'enabled' => env('AGENTICGRAPH_EVENTS_ENABLED', true),
        'listeners' => [],
    ],

    'middleware' => [
        'global' => [
            \Shahzad\AgenticGraph\Middleware\LoggingMiddleware::class,
            \Shahzad\AgenticGraph\Middleware\TimingMiddleware::class,
            \Shahzad\AgenticGraph\Middleware\ValidationMiddleware::class,
        ],
        'per_node' => [],
        'cache_ttl' => env('AGENTICGRAPH_MIDDLEWARE_CACHE_TTL', 3600),
    ],

    'visualization' => [
        'enabled' => env('AGENTICGRAPH_VISUALIZATION', true),
        'format' => env('AGENTICGRAPH_VIZ_FORMAT', 'mermaid'),
    ],

    'logging' => [
        'channel' => env('AGENTICGRAPH_LOG_CHANNEL', 'stack'),
        'level' => env('AGENTICGRAPH_LOG_LEVEL', 'info'),
        'log_state' => env('AGENTICGRAPH_LOG_STATE', false),
    ],

    'circuit_breaker' => [
        'failure_threshold' => env('AGENTICGRAPH_CIRCUIT_BREAKER_THRESHOLD', 5),
        'timeout' => env('AGENTICGRAPH_CIRCUIT_BREAKER_TIMEOUT', 60),
        'retry_after' => env('AGENTICGRAPH_CIRCUIT_BREAKER_RETRY_AFTER', 300),
    ],
];
