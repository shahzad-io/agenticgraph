<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Prism\Prism\Streaming\Events\CitationEvent;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent as PrismStreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;

final class PrismStreamAdapter
{
    public function __construct(
        private readonly StreamSession $session,
        private readonly ?string $nodeName,
        private readonly ?string $spanId,
        private readonly ?string $parentSpanId,
    ) {}

    public function adapt(PrismStreamEvent $event): ?StreamEventImpl
    {
        $type = $this->resolveType($event);
        if ($type === null) {
            return null;
        }

        $payload = $this->resolvePayload($event);
        if (! is_array($payload)) {
            return null;
        }

        $occurredAt = new \DateTimeImmutable('@'.$event->timestamp);
        $seq = ++$this->session->seq;
        $nodeSeq = $this->resolveNodeSeq();

        return new StreamEventImpl(
            $this->session->streamId,
            $this->session->executionId,
            $this->nodeName,
            $this->spanId,
            $this->parentSpanId,
            $type,
            $payload,
            $seq,
            $nodeSeq,
            $occurredAt
        );
    }

    private function resolveNodeSeq(): ?int
    {
        if ($this->nodeName === null || $this->nodeName === '') {
            return null;
        }

        try {
            if (! app()->bound(StreamContext::class)) {
                return null;
            }

            return app(StreamContext::class)->nextNodeSeq($this->nodeName);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveType(PrismStreamEvent $event): ?string
    {
        return match (true) {
            $event instanceof TextStartEvent => 'text_start',
            $event instanceof TextDeltaEvent => 'text_delta',
            $event instanceof TextCompleteEvent => 'text_complete',
            $event instanceof ToolCallEvent => 'tool_call',
            $event instanceof ToolResultEvent => 'tool_result',
            $event instanceof ThinkingStartEvent => 'thinking_start',
            $event instanceof ThinkingEvent => 'thinking_delta',
            $event instanceof ThinkingCompleteEvent => 'thinking_complete',
            $event instanceof StreamStartEvent => 'step_start',
            $event instanceof StreamEndEvent => 'step_finish',
            $event instanceof StepStartEvent => 'step_start',
            $event instanceof StepFinishEvent => 'step_finish',
            $event instanceof ProviderToolEvent => 'provider_tool_event',
            $event instanceof CitationEvent => 'artifact',
            $event instanceof ErrorEvent => 'error',
            default => null,
        };
    }

    private function resolvePayload(PrismStreamEvent $event): mixed
    {
        return match (true) {
            $event instanceof TextStartEvent => [
                'message_id' => $event->messageId,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof TextDeltaEvent => [
                'delta' => $event->delta,
                'message_id' => $event->messageId,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof TextCompleteEvent => [
                'message_id' => $event->messageId,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof ToolCallEvent => [
                'tool_id' => $event->toolCall->id,
                'tool_name' => $event->toolCall->name,
                'arguments' => $event->toolCall->arguments(),
                'message_id' => $event->messageId,
                'reasoning_id' => $event->toolCall->reasoningId,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof ToolResultEvent => [
                'tool_id' => $event->toolResult->toolCallId,
                'tool_name' => $event->toolResult->toolName,
                'result' => $event->toolResult->result,
                'message_id' => $event->messageId,
                'success' => $event->success,
                'error' => $event->error,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof ThinkingStartEvent => [
                'id' => $event->reasoningId,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof ThinkingEvent => [
                'id' => $event->reasoningId,
                'delta' => $event->delta,
                'summary' => $event->summary,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof ThinkingCompleteEvent => [
                'id' => $event->reasoningId,
                'summary' => $event->summary,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof StreamStartEvent => [
                'id' => $event->id,
                'timestamp' => $event->timestamp,
                'model' => $event->model,
                'provider' => $event->provider,
                'metadata' => $event->metadata,
            ],
            $event instanceof StreamEndEvent => [
                'id' => $event->id,
                'timestamp' => $event->timestamp,
                'finish_reason' => $event->finishReason->name,
                'usage' => $event->usage ? [
                    'prompt_tokens' => $event->usage->promptTokens,
                    'completion_tokens' => $event->usage->completionTokens,
                    'cache_write_input_tokens' => $event->usage->cacheWriteInputTokens,
                    'cache_read_input_tokens' => $event->usage->cacheReadInputTokens,
                    'thought_tokens' => $event->usage->thoughtTokens,
                ] : null,
                'citations' => $event->citations,
                'additional_content' => $event->additionalContent,
            ],
            $event instanceof StepStartEvent => [
                'id' => $event->id,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof StepFinishEvent => [
                'id' => $event->id,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof ProviderToolEvent => [
                'event_key' => $event->eventKey(),
                'tool_type' => $event->toolType,
                'status' => $event->status,
                'item_id' => $event->itemId,
                'data' => $event->data,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof CitationEvent => [
                'tool_call_id' => null,
                'tool_name' => null,
                'message_id' => $event->messageId,
                'artifact' => $event->citation,
                'block_index' => $event->blockIndex,
                'metadata' => $event->metadata,
                'timestamp' => $event->timestamp,
            ],
            $event instanceof ErrorEvent => [
                'error_type' => $event->errorType,
                'message' => $event->message,
                'recoverable' => $event->recoverable,
                'metadata' => $event->metadata,
                'timestamp' => $event->timestamp,
            ],
            default => null,
        };
    }
}
