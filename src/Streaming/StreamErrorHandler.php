<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;

class StreamErrorHandler
{
    public function decide(\Throwable $error, StreamEvent $event, string $stage): StreamErrorDecision
    {
        $payload = new StreamErrorPayload(
            errorType: $error::class,
            message: $error->getMessage(),
            stage: $stage,
            eventType: $event->type(),
            eventSeq: $event->seq(),
            eventNodeName: $event->nodeName(),
            eventNodeSeq: $event->nodeSeq()
        );

        return new StreamErrorDecision(true, $payload);
    }
}
