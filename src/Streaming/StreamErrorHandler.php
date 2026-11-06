<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;

class StreamErrorHandler
{
    public function decide(\Throwable $error, StreamEvent $event, string $stage): StreamErrorDecision
    {
        // What the browser sees: generic unless streaming.expose_error_details; the log keeps the real error.
        $client = StreamEventEnvelope::clientError($error, 'StreamError', 'Stream error', providerCategory: false);
        $payload = new StreamErrorPayload(
            errorType: $client['error_type'],
            message: $client['message'],
            stage: $stage,
            eventType: $event->type(),
            eventSeq: $event->seq(),
            eventNodeName: $event->nodeName(),
            eventNodeSeq: $event->nodeSeq()
        );

        $fatal = match ($stage) {
            'store' => (bool) config('agenticgraph.streaming.errors.store_fatal', false),
            'observer' => false,
            'transport', 'emit' => (bool) config('agenticgraph.streaming.errors.transport_fatal', true),
            default => (bool) config('agenticgraph.streaming.errors.transport_fatal', true),
        };

        return new StreamErrorDecision($fatal, $payload);
    }
}
