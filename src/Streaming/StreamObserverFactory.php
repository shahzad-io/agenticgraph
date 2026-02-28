<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

final class StreamObserverFactory
{
    public function create(StreamSession $session): StreamObserver
    {
        return new StreamObserver(
            new StreamMetrics,
            $session->streamId,
            $session->executionId,
            (float) $session->startedAtUnix
        );
    }
}
