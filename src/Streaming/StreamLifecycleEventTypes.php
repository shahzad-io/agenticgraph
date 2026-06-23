<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

/**
 * Lifecycle and framework stream event types excluded from D27 content-emission retry gating.
 */
final class StreamLifecycleEventTypes
{
    /**
     * @var list<string>
     */
    private const LIFECYCLE_TYPES = [
        'graph_start',
        'graph_complete',
        'graph_failed',
        'node_start',
        'node_complete',
        'node_error',
        'stream_resume',
    ];

    public static function isLifecycleOrFrameworkType(string $type): bool
    {
        if (in_array($type, self::LIFECYCLE_TYPES, true)) {
            return true;
        }

        return in_array($type, [
            StreamEventEnvelope::TYPE_EXECUTION_TERMINAL,
            StreamEventEnvelope::TYPE_STREAM_CLOSE,
            StreamEventEnvelope::TYPE_STREAM_ERROR,
        ], true);
    }
}
