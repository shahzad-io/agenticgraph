<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Listeners;

use Illuminate\Contracts\Container\BindingResolutionException;
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
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ModelPricingCalculator;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * Listens to graph execution events and forwards to the configured TracerExporter.
 * Supports configurable capture settings for state, prompts, and responses.
 */
class TracingListener
{
    protected TracerExporter $exporter;

    /** @var array<string, float> */
    protected array $spanStarts = [];

    /** @var array<string, string|null> */
    protected array $spanParents = [];

    /** @var array<string, float> */
    protected array $graphSpanStarts = [];

    /** @var array<string, string> */
    protected array $graphSpanIds = [];

    /** Capture configuration */
    protected string $captureState;

    protected bool $capturePrompts;

    protected bool $captureResponses;

    protected int $captureMaxLength;

    protected ModelPricingCalculator $pricingCalculator;

    public function __construct(TracerExporter $exporter, ?ModelPricingCalculator $pricingCalculator = null)
    {
        $this->exporter = $exporter;
        $this->pricingCalculator = $pricingCalculator ?? new ModelPricingCalculator;

        // Load capture configuration
        $this->captureState = config('agenticgraph.tracing.capture.state', 'diff');
        $this->capturePrompts = config('agenticgraph.tracing.capture.prompts', false);
        $this->captureResponses = config('agenticgraph.tracing.capture.responses', false);
        $this->captureMaxLength = (int) config('agenticgraph.tracing.capture.max_length', 10000);
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

        // Capture initial state based on configuration
        $initialState = $this->captureStateData(
            $event->initialState->toArray(),
            $event->initialState->toArray(), // No previous state at start
            false // Not an error
        );

        $this->exporter->startTrace(
            $event->executionId,
            $event->graphName,
            array_keys($event->initialState->toArray()),
            $event->startedAt,
            $initialState
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

        // Capture final state based on configuration
        $finalState = $this->captureStateData(
            $event->finalState->toArray(),
            $event->finalState->toArray(), // Full state at completion
            false // Not an error
        );

        $this->exporter->completeTrace(
            $event->executionId,
            array_keys($event->finalState->toArray()),
            $durationMs,
            $finalState
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
        $resolvedStartedAt = $endedAt;
        $resolvedEndedAt = $endedAt;
        $this->calculateSpanTimestamps($startedAt, null, $durationMs, $traceStartDt, $resolvedStartedAt, $resolvedEndedAt);

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
            'started_at' => $resolvedStartedAt->format(DATE_ATOM),
            'ended_at' => $resolvedEndedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
            'error' => [
                'type' => get_class($event->exception),
                'message' => $event->exception->getMessage(),
            ],
        ]);

        // On error, capture full state if configured for error_only mode
        $finalState = $this->captureStateData(
            [], // Can't access final state on failure
            [],
            true // Is an error
        );

        $this->exporter->failTrace(
            $event->executionId,
            $event->exception,
            $event->durationMs,
            $finalState
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
        $resolvedStartedAt = $endedAt;
        $resolvedEndedAt = $endedAt;
        $this->calculateSpanTimestamps($startedAt, null, $durationMs, $traceStartDt, $resolvedStartedAt, $resolvedEndedAt);

        // Extract loop context if available
        $loopContext = null;
        try {
            $executionContext = app(ExecutionContext::class);
            $loopContext = [
                'global_iterations' => $executionContext->globalIterations,
                'node_visit_count' => $executionContext->nodeVisitCounts[$event->nodeName] ?? 0,
                'active_cycles' => array_map(
                    fn ($hash, $count) => ['cycle' => $hash, 'count' => $count],
                    array_keys($executionContext->cycleCompletionCounts),
                    $executionContext->cycleCompletionCounts
                ),
                'path_depth' => count($executionContext->pathStack),
                'in_cycle' => ! empty($executionContext->cycleCompletionCounts),
            ];
        } catch (BindingResolutionException $e) {
            // ExecutionContext not bound (loop disabled or old execution)
        }

        // Capture state diff based on configuration
        $stateDiff = $this->buildStateDiff(
            $event->inputState->toArray(),
            $event->outputState->toArray(),
            false // Not an error
        );

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
            'started_at' => $resolvedStartedAt->format(DATE_ATOM),
            'ended_at' => $resolvedEndedAt->format(DATE_ATOM),
            'duration_ms' => $durationMs,
            'state_diff' => [
                'added' => array_diff($outputKeys, $inputKeys),
                'removed' => array_diff($inputKeys, $outputKeys),
                'values' => $stateDiff,
            ],
            'loop_context' => $loopContext,
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
        $endedAt = $startedAt ?? new \DateTimeImmutable;
        $traceStartDt = $traceStart ? new \DateTimeImmutable($traceStart->toIso8601String()) : null;
        $resolvedStartedAt = $endedAt;
        $resolvedEndedAt = $endedAt;
        $this->calculateSpanTimestamps($startedAt, null, $event->durationMs, $traceStartDt, $resolvedStartedAt, $resolvedEndedAt);

        $this->exporter->addSpan([
            'id' => $event->spanId ?? uniqid('span_', true),
            'trace_id' => $event->executionId,
            'parent_id' => $event->parentSpanId,
            'node_name' => $event->nodeName,
            'span_type' => 'node',
            'status' => 'failed',
            'span_kind' => 'internal',
            'status_code' => 'ERROR',
            'started_at' => $resolvedStartedAt->format(DATE_ATOM),
            'ended_at' => $resolvedEndedAt->format(DATE_ATOM),
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
        } catch (BindingResolutionException $e) {
            // TraceContext not bound, tracing not active
        }

        $executionId = $event->executionId ?? $traceContext?->getExecutionId();
        $spanId = $event->spanId ?? $traceContext?->getSpanId();

        $pricing = $this->pricingCalculator->calculate($event->model, $event->promptTokens, $event->completionTokens);
        $cost = $pricing['cost_usd'];

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

        // Build LLM call data with optional prompt/response capture
        $llmCallData = [
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
        ];

        // Add prompt if capture is enabled
        if ($this->capturePrompts && $event->prompt !== null) {
            $llmCallData['prompt'] = $this->truncateText($event->prompt, $this->captureMaxLength);
        }

        // Add response if capture is enabled
        if ($this->captureResponses && $event->response !== null) {
            $llmCallData['response'] = $this->truncateText($event->response, $this->captureMaxLength);
        }

        $this->exporter->addLlmCall($llmCallData);
    }

    public function onToolCallCaptured(ToolCallCaptured $event): void
    {
        if (! $this->exporter->isEnabled() || $event->spanAlreadyExported) {
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

    /**
     * Capture state data based on configuration.
     *
     * @param  array<string, mixed>  $currentState
     * @param  array<string, mixed>  $previousState
     * @return array<string, mixed>|null
     */
    private function captureStateData(array $currentState, array $previousState, bool $isError): ?array
    {
        return match ($this->captureState) {
            'none' => null,
            'full' => $currentState,
            'error_only' => $isError ? $currentState : null,
            default => null, // 'diff' mode returns null at trace level (handled per-node)
        };
    }

    /**
     * Build state diff based on configuration.
     *
     * @param  array<string, mixed>  $inputState
     * @param  array<string, mixed>  $outputState
     * @return array<string, mixed>|null
     */
    private function buildStateDiff(array $inputState, array $outputState, bool $isError): ?array
    {
        return match ($this->captureState) {
            'none' => null,
            'full' => $outputState,
            'diff' => $this->computeDiff($inputState, $outputState),
            'error_only' => $isError ? $outputState : null,
            default => null,
        };
    }

    /**
     * Compute difference between input and output state.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function computeDiff(array $input, array $output): array
    {
        $diff = [];

        // Find added and changed keys
        foreach ($output as $key => $value) {
            if (! array_key_exists($key, $input)) {
                $diff[$key] = ['type' => 'added', 'value' => $value];
            } elseif ($input[$key] !== $value) {
                $diff[$key] = ['type' => 'changed', 'old' => $input[$key], 'new' => $value];
            }
        }

        // Find removed keys
        foreach ($input as $key => $value) {
            if (! array_key_exists($key, $output)) {
                $diff[$key] = ['type' => 'removed', 'old' => $value];
            }
        }

        return $diff;
    }

    /**
     * Truncate text to maximum allowed length with ellipsis indicator.
     */
    private function truncateText(?string $text, int $maxLength): ?string
    {
        if ($text === null || strlen($text) <= $maxLength) {
            return $text;
        }

        // Try to truncate at sentence boundary first
        $truncated = substr($text, 0, $maxLength - 20);
        $lastSentence = strrpos($truncated, '. ');

        if ($lastSentence !== false && $lastSentence > $maxLength * 0.7) {
            return substr($truncated, 0, $lastSentence + 1).' [...truncated]';
        }

        // Fallback: truncate at word boundary
        $lastSpace = strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            return substr($truncated, 0, $lastSpace).' [...truncated]';
        }

        // Final fallback: hard truncate
        return substr($text, 0, $maxLength - 15).' [...truncated]';
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
}
