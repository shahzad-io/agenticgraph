<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

/**
 * Lifecycle and framework stream event types excluded from the content-emission retry gate.
 */
final class StreamLifecycleEventTypes
{
    public const GRAPH_START = 'graph_start';

    public const GRAPH_COMPLETE = 'graph_complete';

    public const GRAPH_FAILED = 'graph_failed';

    public const NODE_START = 'node_start';

    public const NODE_COMPLETE = 'node_complete';

    public const NODE_ERROR = 'node_error';

    public const NODE_INTERRUPT = 'node_interrupt';

    public const STREAM_RESUME = 'stream_resume';

    /**
     * @var list<string>
     */
    private const LIFECYCLE_TYPES = [
        self::GRAPH_START,
        self::GRAPH_COMPLETE,
        self::GRAPH_FAILED,
        self::NODE_START,
        self::NODE_COMPLETE,
        self::NODE_ERROR,
        self::NODE_INTERRUPT,
        self::STREAM_RESUME,
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
