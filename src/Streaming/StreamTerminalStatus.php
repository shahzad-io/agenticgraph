<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

enum StreamTerminalStatus: string
{
    case COMPLETED = 'completed';
    case INTERRUPTED = 'interrupted';
    case FAILED = 'failed';

    public static function fromStreamStatus(StreamStatus $status): self
    {
        return match ($status) {
            StreamStatus::SUCCESS => self::COMPLETED,
            StreamStatus::CANCELLED => self::INTERRUPTED,
            StreamStatus::ERROR => self::FAILED,
        };
    }
}
