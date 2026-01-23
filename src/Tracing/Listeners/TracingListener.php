<?php

namespace Shahzad\AgenticGraph\Tracing\Listeners;

use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * Listens to graph execution events and forwards to PulseExporter.
 */
class TracingListener
{
    protected PulseExporter $exporter;

    public function __construct(PulseExporter $exporter)
    {
        $this->exporter = $exporter;
    }

    /**
     * Handle graph execution started.
     */
    public function onGraphStarted(GraphExecutionStarted $event): void
    {
        if (! $this->exporter->isEnabled()) {
            return;
        }

        $this->exporter->startTrace(
            $event->executionId,
            $event->graphName,
            array_keys($event->initialState->toArray())
        );
    }

    /**
     * Handle graph execution completed.
     */
    public function onGraphCompleted(GraphExecutionCompleted $event): void
    {
        if (! $this->exporter->isEnabled()) {
            return;
        }

        $this->exporter->completeTrace(
            $event->executionId,
            array_keys($event->finalState->toArray()),
            $event->duration * 1000 // Convert to ms
        );
    }

    /**
     * Handle graph execution failed.
     */
    public function onGraphFailed(GraphExecutionFailed $event): void
    {
        if (! $this->exporter->isEnabled()) {
            return;
        }

        $this->exporter->failTrace(
            $event->executionId,
            $event->exception,
            $event->durationMs
        );
    }

    /**
     * Handle node execution started.
     */
    public function onNodeStarted(NodeExecutionStarted $event): void
    {
        // Span is already tracked via TraceContext in GraphExecutor
        // This listener can add additional metadata if needed
    }

    /**
     * Handle node execution completed.
     */
    public function onNodeCompleted(NodeExecutionCompleted $event): void
    {
        if (! $this->exporter->isEnabled()) {
            return;
        }

        $inputKeys = array_keys($event->inputState->toArray());
        $outputKeys = array_keys($event->outputState->toArray());

        $this->exporter->addSpan([
            'id' => $event->spanId,
            'trace_id' => $event->executionId,
            'parent_id' => null, // Could be enhanced with parent tracking
            'node_name' => $event->nodeName,
            'status' => 'success',
            'started_at' => null, // Set from NodeExecutionStarted if needed
            'duration_ms' => $event->duration * 1000,
            'state_diff' => [
                'added' => array_diff($outputKeys, $inputKeys),
                'removed' => array_diff($inputKeys, $outputKeys),
            ],
        ]);
    }

    /**
     * Handle node execution failed.
     */
    public function onNodeFailed(NodeExecutionFailed $event): void
    {
        if (! $this->exporter->isEnabled()) {
            return;
        }

        $this->exporter->addSpan([
            'id' => $event->spanId,
            'trace_id' => $event->executionId,
            'node_name' => $event->nodeName,
            'status' => 'failed',
            'duration_ms' => $event->durationMs,
            'error' => [
                'type' => get_class($event->exception),
                'message' => $event->exception->getMessage(),
            ],
            'retry_attempt' => $event->retryAttempt,
            'will_retry' => $event->willRetry,
        ]);
    }

    /**
     * Handle tokens used (LLM calls).
     */
    public function onTokensUsed(TokensUsed $event): void
    {
        if (! $this->exporter->isEnabled()) {
            return;
        }

        $this->exporter->addLlmCall([
            'id' => uniqid('llm_', true),
            'trace_id' => TraceContext::getExecutionId(),
            'span_id' => TraceContext::getSpanId(),
            'node' => $event->node,
            'call_type' => $event->callType,
            'model' => $event->model,
            'prompt_tokens' => $event->promptTokens,
            'completion_tokens' => $event->completionTokens,
            'total_tokens' => $event->totalTokens,
            'duration_ms' => $event->durationMs,
        ]);
    }

    /**
     * Subscribe to events.
     *
     * @return array<string, string>
     */
    public function subscribe($events): array
    {
        return [
            GraphExecutionStarted::class => 'onGraphStarted',
            GraphExecutionCompleted::class => 'onGraphCompleted',
            GraphExecutionFailed::class => 'onGraphFailed',
            NodeExecutionStarted::class => 'onNodeStarted',
            NodeExecutionCompleted::class => 'onNodeCompleted',
            NodeExecutionFailed::class => 'onNodeFailed',
            TokensUsed::class => 'onTokensUsed',
        ];
    }
}
