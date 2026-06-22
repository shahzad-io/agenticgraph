<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Streaming\Contracts\StreamSinkInterface;

final class StreamLifecycleEventEmitter
{
    public static function emit(
        StreamSinkInterface $sink,
        StreamSession $session,
        ?string $nodeName,
        ?string $spanId,
        ?string $parentSpanId,
        string $type,
        mixed $payload,
        ?int $nodeSeq = null
    ): void {
        $seq = ++$session->seq;
        $payloadWithStream = is_array($payload) ? $payload : ['payload' => $payload];
        $payloadWithStream['stream'] = [
            'seq' => $seq,
            'node_seq' => $nodeSeq,
            'event_type' => $type,
            'bytes' => strlen(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $envelope = new StreamEventEnvelope(
            type: $type,
            streamId: $session->streamId,
            executionId: $session->executionId,
            nodeName: $nodeName,
            spanId: $spanId,
            parentSpanId: $parentSpanId,
            seq: $seq,
            nodeSeq: $nodeSeq,
            occurredAt: new \DateTimeImmutable,
            payload: $payloadWithStream,
        );

        $sink->emit($envelope->toStreamEvent());
    }
}
