<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;

final class NodeStreamAccumulator
{
    private mixed $textsByMessageId = [];

    private mixed $toolCallDeltasById = [];

    private mixed $toolCallsById = [];

    private mixed $toolCallOrder = [];

    private mixed $toolResultsById = [];

    private mixed $toolResultOrder = [];

    private mixed $thinkingById = [];

    public function __construct(
        private readonly StateContract $state,
        private readonly string $textKey = 'text',
        private readonly string $toolCallsKey = 'tool_calls',
        private readonly string $toolResultsKey = 'tool_results',
        private readonly string $thinkingKey = 'thinking'
    ) {}

    public function apply(StreamEvent $event): void
    {
        $payload = $event->payload();
        if (! is_array($payload)) {
            return;
        }

        $messageId = $payload['message_id'] ?? null;
        $toolId = $payload['tool_id'] ?? null;
        $reasoningId = $payload['id'] ?? ($payload['reasoning_id'] ?? null);

        switch ($event->type()) {
            case 'text_start':
                if ($messageId !== null && ! isset($this->textsByMessageId[$messageId])) {
                    $this->textsByMessageId[$messageId] = '';
                }
                break;
            case 'text_delta':
                if ($messageId !== null && is_string($payload['delta'] ?? null)) {
                    $this->textsByMessageId[$messageId] = ($this->textsByMessageId[$messageId] ?? '').$payload['delta'];
                }
                break;
            case 'tool_call_delta':
                if ($toolId !== null && is_string($payload['delta'] ?? null)) {
                    $this->toolCallDeltasById[$toolId] = ($this->toolCallDeltasById[$toolId] ?? '').$payload['delta'];
                }
                break;
            case 'tool_call':
                if ($toolId !== null) {
                    if (! in_array($toolId, $this->toolCallOrder, true)) {
                        $this->toolCallOrder[] = $toolId;
                    }
                    $arguments = $payload['arguments'] ?? null;
                    if ($arguments === null && isset($this->toolCallDeltasById[$toolId])) {
                        $arguments = $this->decodeJsonOrRaw($this->toolCallDeltasById[$toolId]);
                    }
                    $this->toolCallsById[$toolId] = [
                        'tool_id' => $toolId,
                        'tool_name' => $payload['tool_name'] ?? null,
                        'arguments' => $arguments,
                        'message_id' => $messageId,
                    ];
                }
                break;
            case 'tool_result':
                if ($toolId !== null) {
                    if (! in_array($toolId, $this->toolResultOrder, true)) {
                        $this->toolResultOrder[] = $toolId;
                    }
                    $this->toolResultsById[$toolId] = [
                        'tool_id' => $toolId,
                        'tool_name' => $payload['tool_name'] ?? null,
                        'result' => $payload['result'] ?? null,
                        'message_id' => $messageId,
                        'success' => $payload['success'] ?? null,
                        'error' => $payload['error'] ?? null,
                    ];
                }
                break;
            case 'thinking_start':
                if ($reasoningId !== null && ! isset($this->thinkingById[$reasoningId])) {
                    $this->thinkingById[$reasoningId] = '';
                }
                break;
            case 'thinking_delta':
                if ($reasoningId !== null && is_string($payload['delta'] ?? null)) {
                    $this->thinkingById[$reasoningId] = ($this->thinkingById[$reasoningId] ?? '').$payload['delta'];
                }
                break;
            case 'thinking_complete':
                if ($reasoningId !== null && is_string($payload['summary'] ?? null)) {
                    $this->thinkingById[$reasoningId] = $payload['summary'];
                }
                break;
        }
    }

    public function finalize(): StateContract
    {
        $state = $this->state;

        if ($this->textsByMessageId !== []) {
            $state = $state->set($this->textKey, $this->normalizeSingle($this->textsByMessageId));
        }

        if ($this->toolCallsById !== []) {
            $ordered = [];
            foreach ($this->toolCallOrder as $toolId) {
                if (isset($this->toolCallsById[$toolId])) {
                    $ordered[] = $this->toolCallsById[$toolId];
                }
            }
            $state = $state->set($this->toolCallsKey, $ordered);
        }

        if ($this->toolResultsById !== []) {
            $ordered = [];
            foreach ($this->toolResultOrder as $toolId) {
                if (isset($this->toolResultsById[$toolId])) {
                    $ordered[] = $this->toolResultsById[$toolId];
                }
            }
            $state = $state->set($this->toolResultsKey, $ordered);
        }

        if ($this->thinkingById !== []) {
            $state = $state->set($this->thinkingKey, $this->normalizeSingle($this->thinkingById));
        }

        return $state;
    }

    private function normalizeSingle(mixed $values): mixed
    {
        if (! is_array($values)) {
            return $values;
        }
        if (count($values) === 1) {
            return reset($values);
        }

        return $values;
    }

    private function decodeJsonOrRaw(string $value): mixed
    {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }
}
