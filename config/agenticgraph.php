<?php

return [
    'storage' => env('AGENTICGRAPH_STORAGE', 'database'),

    'checkpoint' => [
        'enabled' => env('AGENTICGRAPH_CHECKPOINT_ENABLED', true),
        'ttl' => env('AGENTICGRAPH_CHECKPOINT_TTL', 7),
    ],

    'execution' => [
        'queue_connection' => env('AGENTICGRAPH_QUEUE_CONNECTION', 'redis'),
        'queue_name' => env('AGENTICGRAPH_QUEUE_NAME', 'agenticgraph'),
        'max_iterations' => env('AGENTICGRAPH_MAX_ITERATIONS', 1000),
    ],

    'lock' => [
        'timeout' => env('AGENTICGRAPH_LOCK_TIMEOUT', 10),
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
            \Shahzad\AgenticGraph\Middleware\LoggingMiddleware::class,
            \Shahzad\AgenticGraph\Middleware\TimingMiddleware::class,
            \Shahzad\AgenticGraph\Middleware\ValidationMiddleware::class,
        ],
        'cache_ttl' => env('AGENTICGRAPH_MIDDLEWARE_CACHE_TTL', 3600),
    ],

    'circuit_breaker' => [
        'failure_threshold' => env('AGENTICGRAPH_CIRCUIT_BREAKER_THRESHOLD', 5),
        'timeout' => env('AGENTICGRAPH_CIRCUIT_BREAKER_TIMEOUT', 60),
        'retry_after' => env('AGENTICGRAPH_CIRCUIT_BREAKER_RETRY_AFTER', 300),
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

        // AgenticPulse API credentials
        'api_key' => env('AGENTICPULSE_API_KEY'),
        'endpoint' => env('AGENTICPULSE_ENDPOINT', 'https://api.agenticpulse.io'),

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
        'buffer' => [
            'store' => env('AGENTICGRAPH_TRACING_BUFFER_STORE'),
            'ttl_seconds' => env('AGENTICGRAPH_TRACING_BUFFER_TTL', 3600),
        ],
    ],
];
