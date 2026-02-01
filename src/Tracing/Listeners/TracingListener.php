<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Listeners;

use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Events\NodeResumed;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * Listens to graph execution events and forwards to PulseExporter.
 */
class TracingListener
{
    protected PulseExporter $exporter;

    /** @var array<string, float> */
    protected array $spanStarts = [];

    /** @var array<string, string|null> */
    protected array $spanParents = [];

    /** @var array<string, float> */
    protected array $graphSpanStarts = [];

    /** @var array<string, string> */
    protected array $graphSpanIds = [];

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

        $spanId = $event->spanId ?? uniqid('span_', true);

        $this->exporter->startTrace(
            $event->executionId,
            $event->graphName,
            array_keys($event->initialState->toArray()),
            $event->startedAt
        );

        // We can add the graph span immediately or wait for completion.
        // Waiting for completion is typical for this exporter structure.
    }

    /**
     * Handle graph execution completed.
     */
    public function onGraphCompleted(GraphExecutionCompleted $event): void
    {
        if (! $this->exporter->isEnabled()) {
            return;
        }

        $spanId = $event->spanId ?? uniqid('span_', true);
        $durationMs = $event->duration * 1000;

        $traceStart = $this->exporter->getTraceStartTime($event->executionId);
        if ($traceStart) {
            $startedAt = new \DateTimeImmutable($traceStart->toIso8601String());
        } else {
            // Use DateInterval subtraction instead of modify() for proper fractional second handling
            $durationSeconds = $event->duration;
            $wholeSeconds = intval($durationSeconds);
            $fractionalSeconds = $durationSeconds - $wholeSeconds;

            $interval = new \DateInterval("PT{$wholeSeconds}S");
            if ($fractionalSeconds > 0) {
                $interval->f = $fractionalSeconds;
            }
            $interval->invert = 1; // Invert for subtraction

            $startedAt = $event->completedAt->sub($interval);
        }
        $endedAt = $startedAt;
        $traceStartDt = $traceStart ? new \DateTimeImmutable($traceStart->toIso8601String()) : null;
        $this->calculateSpanTimestamps($startedAt, null, $durationMs, $traceStartDt, $startedAt, $endedAt);

        $this->exporter->addSpan([
            'id' => $spanId,
            'trace_id' => $event->executionId,
            'parent_id' => null,
            'node_name' => $event->graphName,
            'node_type' => 'graph',
            'span_type' => 'graph',
            'status' => 'success',
            'span_kind' => 'internal',
            'status_code' => 'OK',
            'started_at' => $startedAt->format(DATE_ATOM),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
        ]);

        $this->exporter->completeTrace(
            $event->executionId,
            array_keys($event->finalState->toArray()),
            $durationMs
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

        $spanId = uniqid('span_', true);
        $durationMs = $event->durationMs;

        $traceStart = $this->exporter->getTraceStartTime($event->executionId);
        $startedAt = $traceStart ? new \DateTimeImmutable($traceStart->toIso8601String()) : null;
        $endedAt = $startedAt ?? new \DateTimeImmutable;
        $traceStartDt = $traceStart ? new \DateTimeImmutable($traceStart->toIso8601String()) : null;
        $this->calculateSpanTimestamps($startedAt, null, $durationMs, $traceStartDt, $startedAt, $endedAt);

        $this->exporter->addSpan([
            'id' => $spanId,
            'trace_id' => $event->executionId,
            'parent_id' => null,
            'node_name' => $event->graphName,
            'node_type' => 'graph',
            'span_type' => 'graph',
            'status' => 'failed',
            'span_kind' => 'internal',
            'status_code' => 'ERROR',
            'started_at' => $startedAt->format(DATE_ATOM),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
            'error' => [
                'type' => get_class($event->exception),
                'message' => $event->exception->getMessage(),
            ],
        ]);

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
        // No-op for exporter as we export on completion.
        // Removing in-memory state tracking to fixes async issues.
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

        $durationMs = $event->duration * 1000;
        $traceStart = $this->exporter->getTraceStartTime($event->executionId);
        $startedAt = $event->startedAt;
        if (! $startedAt && $traceStart) {
            $startedAt = new \DateTimeImmutable($traceStart->toIso8601String());
        }
        $endedAt = $startedAt ?? new \DateTimeImmutable;
        $traceStartDt = $traceStart ? new \DateTimeImmutable($traceStart->toIso8601String()) : null;
        $this->calculateSpanTimestamps($startedAt, null, $durationMs, $traceStartDt, $startedAt, $endedAt);

        $this->exporter->addSpan([
            'id' => $event->spanId ?? uniqid('span_', true),
            'trace_id' => $event->executionId,
            'parent_id' => $event->parentSpanId,
            'node_name' => $event->nodeName,
            'node_type' => $event->metadata['node_type'] ?? null,
            'span_type' => 'node',
            'status' => 'success',
            'span_kind' => 'internal',
            'status_code' => 'OK',
            'started_at' => $startedAt->format(DATE_ATOM),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
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

        $traceStart = $this->exporter->getTraceStartTime($event->executionId);
        $startedAt = $event->startedAt;
        $endedAt = $startedAt;
        $traceStartDt = $traceStart ? new \DateTimeImmutable($traceStart->toIso8601String()) : null;
        $this->calculateSpanTimestamps($startedAt, null, $event->durationMs, $traceStartDt, $startedAt, $endedAt);

        $this->exporter->addSpan([
            'id' => $event->spanId ?? uniqid('span_', true),
            'trace_id' => $event->executionId,
            'parent_id' => $event->parentSpanId,
            'node_name' => $event->nodeName,
            'span_type' => 'node',
            'status' => 'failed',
            'span_kind' => 'internal',
            'status_code' => 'ERROR',
            'started_at' => $startedAt->format(DATE_ATOM),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'duration_ms' => $event->durationMs,
            'error' => [
                'type' => get_class($event->exception),
                'message' => $event->exception->getMessage(),
            ],
            'retry_count' => $event->retryAttempt,
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

        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            // TraceContext not bound, tracing not active
        }

        $executionId = $event->executionId ?? $traceContext?->getExecutionId();
        $spanId = $event->spanId ?? $traceContext?->getSpanId();

        $cost = $this->calculateCost($event->model, $event->promptTokens, $event->completionTokens);

        $durationMs = $event->durationMs ?? 0.0;

        $traceStart = $executionId ? $this->exporter->getTraceStartTime($executionId) : null;
        $fallbackStart = $traceStart ? new \DateTimeImmutable($traceStart->toIso8601String()) : null;
        $eventStartedAt = $event->startedAt instanceof \DateTimeImmutable ? $event->startedAt : null;
        $eventEndedAt = $event->endedAt instanceof \DateTimeImmutable ? $event->endedAt : null;
        $startedAt = $eventStartedAt ?? $eventEndedAt ?? $fallbackStart ?? new \DateTimeImmutable;
        $endedAt = $eventEndedAt ?? $startedAt;
        $traceStartDt = $traceStart ? new \DateTimeImmutable($traceStart->toIso8601String()) : null;
        $this->calculateSpanTimestamps($eventStartedAt, $eventEndedAt, $durationMs, $traceStartDt, $startedAt, $endedAt);

        $parentId = $event->parentSpanId ?? $spanId ?? ($executionId ? ($this->graphSpanIds[$executionId] ?? null) : null);
        // Fallback for parent if tracing context lost?
        // In async, we might lose parentID if not passed.
        // But TokensUsed usually happens inside Node execution where context is set (if properly handled).

        $this->exporter->addSpan([
            'id' => $spanId ?? uniqid('span_', true),
            'trace_id' => $executionId,
            'parent_id' => $parentId,
            'node_name' => $event->node.' llm',
            'node_type' => 'llm',
            'span_type' => 'llm',
            'status' => 'success',
            'span_kind' => 'client',
            'status_code' => 'OK',
            'started_at' => $startedAt->format(DATE_ATOM),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
            'model' => $event->model,
            'prompt_tokens' => $event->promptTokens,
            'completion_tokens' => $event->completionTokens,
            'total_tokens' => $event->totalTokens,
            'cost_usd' => $cost,
        ]);

        // ... (keep addLlmCall) ...
        $this->exporter->addLlmCall([
            'id' => uniqid('llm_', true),
            'trace_id' => $executionId,
            'span_id' => $spanId,
            'node' => $event->node,
            'call_type' => $event->callType,
            'model' => $event->model,
            'prompt_tokens' => $event->promptTokens,
            'completion_tokens' => $event->completionTokens,
            'total_tokens' => $event->totalTokens,
            'duration_ms' => $event->durationMs,
            'created_at' => now()->toIso8601String(),
            'cost_usd' => $cost,
        ]);
    }

    public function onToolCallCaptured(ToolCallCaptured $event): void
    {
        if (! $this->exporter->isEnabled()) {
            return;
        }

        $durationMs = $event->durationMs ?? 0;
        $traceStart = $this->exporter->getTraceStartTime($event->executionId);
        $startedAt = $event->startedAt;
        $endedAt = $startedAt;
        $traceStartDt = $traceStart ? new \DateTimeImmutable($traceStart->toIso8601String()) : null;
        $this->calculateSpanTimestamps($startedAt, null, $durationMs, $traceStartDt, $startedAt, $endedAt);
        $status = $event->status;
        $statusCode = $status === 'success' ? 'OK' : 'ERROR';
        $parentId = $event->spanId ?? null;

        $this->exporter->addSpan([
            'id' => uniqid('span_', true),
            'trace_id' => $event->executionId,
            'parent_id' => $parentId,
            'node_name' => $event->toolName,
            'node_type' => 'tool',
            'span_type' => 'tool',
            'status' => $status,
            'span_kind' => 'internal',
            'status_code' => $statusCode,
            'started_at' => $startedAt->format(DATE_ATOM),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
            'tool_call_id' => $event->toolCallId,
            'tool_type' => $event->toolType,
            'tool_provider' => $event->toolProvider,
            'tool_request_id' => $event->toolRequestId,
            'input_size' => $event->inputSize,
            'output_size' => $event->outputSize,
            'error' => $event->errorType || $event->errorMessage ? [
                'type' => $event->errorType,
                'message' => $event->errorMessage,
            ] : null,
        ]);
    }

    /**
     * Handle node interrupted event.
     */
    public function onNodeInterrupted(NodeInterrupted $event): void
    {
        if (! $this->exporter->isEnabled()) {
            return;
        }

        // Create a span for the interrupt
        $this->exporter->addSpan([
            'id' => uniqid('span_', true),
            'trace_id' => $event->executionId,
            'parent_id' => null, // Top-level interrupt span
            'node_name' => $event->nodeName.' (interrupted)',
            'node_type' => 'interrupt',
            'span_type' => 'interrupt',
            'status' => 'interrupted',
            'span_kind' => 'internal',
            'status_code' => 'PAUSED',
            'started_at' => $event->interruptedAt->format(DATE_ATOM),
            'ended_at' => $event->interruptedAt->format(DATE_ATOM),
            'duration_ms' => 0, // Instant event
            'interrupt_reason' => $event->reason,
            'checkpoint_id' => $event->checkpointId,
            'state_snapshot' => array_keys($event->stateSnapshot), // Just keys for size
        ]);
    }

    /**
     * Handle node resumed event.
     */
    public function onNodeResumed(NodeResumed $event): void
    {
        if (! $this->exporter->isEnabled()) {
            return;
        }

        // Create a span for the resume
        $this->exporter->addSpan([
            'id' => uniqid('span_', true),
            'trace_id' => $event->executionId,
            'parent_id' => null, // Top-level resume span
            'node_name' => $event->nodeName.' (resumed)',
            'node_type' => 'resume',
            'span_type' => 'resume',
            'status' => 'resumed',
            'span_kind' => 'internal',
            'status_code' => 'OK',
            'started_at' => $event->resumedAt->format(DATE_ATOM),
            'ended_at' => $event->resumedAt->format(DATE_ATOM),
            'duration_ms' => 0, // Instant event
            'checkpoint_id' => $event->checkpointId,
            'state_snapshot' => array_keys($event->stateSnapshot), // Just keys for size
        ]);
    }

    /**
     * Subscribe to events.
     *
     * @return array<string, string>
     */
    /**
     * @param  object  $events
     * @return array<class-string, string>
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
            ToolCallCaptured::class => 'onToolCallCaptured',
            // Interrupt events
            NodeInterrupted::class => 'onNodeInterrupted',
            NodeResumed::class => 'onNodeResumed',
        ];
    }

    private function calculateSpanTimestamps(
        ?\DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        float $durationMs,
        ?\DateTimeImmutable $traceStart,
        \DateTimeImmutable &$resolvedStartedAt,
        \DateTimeImmutable &$resolvedEndedAt
    ): void {
        $durationSeconds = max($durationMs / 1000, 0);

        if ($startedAt instanceof \DateTimeImmutable) {
            $resolvedStartedAt = $startedAt;
            $resolvedEndedAt = $this->addSecondsToDateTime($startedAt, $durationSeconds);

            return;
        }

        if ($endedAt instanceof \DateTimeImmutable) {
            $resolvedStartedAt = $this->addSecondsToDateTime($endedAt, -$durationSeconds);
            $resolvedEndedAt = $endedAt;

            return;
        }

        // Use trace start as the base for calculating timestamps when event timestamps are missing
        // This ensures consistent timestamps even in async/queued scenarios
        $baseTime = $traceStart ?? new \DateTimeImmutable;
        $resolvedStartedAt = $baseTime;
        $resolvedEndedAt = $this->addSecondsToDateTime($baseTime, $durationSeconds);
    }

    /**
     * Add seconds to a DateTimeImmutable using DateInterval to properly handle fractional seconds.
     * PHP's DateTime::modify() ignores decimal points in the offset string.
     */
    private function addSecondsToDateTime(\DateTimeImmutable $dt, float $seconds): \DateTimeImmutable
    {
        $wholeSeconds = intval($seconds);
        $fractionalSeconds = $seconds - $wholeSeconds;

        // Create interval with whole seconds
        $interval = new \DateInterval("PT{$wholeSeconds}S");

        // Set fractional seconds using the 'f' property (PHP 7.1+)
        if ($fractionalSeconds > 0) {
            $interval->f = $fractionalSeconds;
        }

        return $dt->add($interval);
    }

    private function calculateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        // Simple rate table (approximate)
        $rates = [
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
            'claude-3-5-sonnet' => ['input' => 3.00, 'output' => 15.00],
        ];

        // Normalize model name
        $modelKey = null;
        foreach (array_keys($rates) as $key) {
            if (str_contains($model, $key)) {
                $modelKey = $key;
                break;
            }
        }

        if (! $modelKey) {
            return 0.0;
        }

        $inputCost = ($promptTokens / 1_000_000) * $rates[$modelKey]['input'];
        $outputCost = ($completionTokens / 1_000_000) * $rates[$modelKey]['output'];

        return round($inputCost + $outputCost, 6);
    }
}
