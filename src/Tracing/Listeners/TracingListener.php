<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tracing\Listeners;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\LlmCallFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Events\NodeResumed;
use Shahzad\AgenticGraph\Events\RouteDecided;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Tracing\CaptureDepth;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ModelPricingCalculator;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceTime;

/**
 * Listens to graph execution events and forwards to the configured TracerExporter.
 * Supports configurable capture settings for state, prompts, and responses.
 */
class TracingListener
{
    protected ?TracerExporter $exporter;

    /** Capture configuration */
    protected string $captureState;

    protected bool $capturePrompts;

    protected bool $captureResponses;

    protected int $captureMaxLength;

    protected ModelPricingCalculator $pricingCalculator;

    public function __construct(?TracerExporter $exporter = null, ?ModelPricingCalculator $pricingCalculator = null)
    {
        $this->exporter = $exporter;
        $this->pricingCalculator = $pricingCalculator ?? app(ModelPricingCalculator::class);

        // Load capture configuration
        $this->captureState = config('agenticgraph.tracing.capture.state', 'keys');
        $this->capturePrompts = config('agenticgraph.tracing.capture.prompts', false);
        $this->captureResponses = config('agenticgraph.tracing.capture.responses', false);
        $this->captureMaxLength = (int) config('agenticgraph.tracing.capture.max_length', 2000);
    }

    /**
     * Handle graph execution started.
     */
    public function onGraphStarted(GraphExecutionStarted $event): void
    {
        if (! $this->exporter()->isEnabled()) {
            return;
        }

        $spanId = $event->spanId ?? uniqid('span_', true);

        // Capture initial state based on configuration
        $initialState = $this->captureStateData(
            $event->initialState->toArray(),
            $event->initialState->toArray(), // No previous state at start
            false // Not an error
        );

        $this->exporter()->startTrace(
            $event->executionId,
            $event->graphName,
            array_keys($event->initialState->toArray()),
            $event->startedAt,
            $initialState
        );
    }

    /**
     * Handle graph execution completed.
     *
     * Terminal trace flush for successful runs. {@see ExecutionContextManager::exit()}
     * intentionally does not call completeTrace so queued span listeners can finish first.
     */
    public function onGraphCompleted(GraphExecutionCompleted $event): void
    {
        if (! $this->exporter()->isEnabled()) {
            return;
        }

        $spanId = $event->spanId ?? uniqid('span_', true);
        $traceStartDt = $this->traceStart($event->executionId);
        $durationMs = $this->graphDurationMs($traceStartDt, $event->completedAt, $event->duration * 1000);
        $startedAt = $traceStartDt ?? TraceTime::addMs($event->completedAt, -$durationMs);
        $endedAt = $startedAt;
        $this->calculateSpanTimestamps($startedAt, null, $durationMs, $traceStartDt, $startedAt, $endedAt);

        $this->exporter()->addSpan([
            'id' => $spanId,
            'trace_id' => $event->executionId,
            'parent_id' => null,
            'node_name' => $event->graphName,
            'node_type' => 'graph',
            'span_type' => 'graph',
            'status' => 'success',
            'span_kind' => 'internal',
            'status_code' => 'OK',
            'started_at' => TraceTime::format($startedAt),
            'ended_at' => TraceTime::format($endedAt),
            'duration_ms' => $durationMs,
        ]);

        // Capture final state based on configuration
        $finalState = $this->captureStateData(
            $event->finalState->toArray(),
            $event->finalState->toArray(), // Full state at completion
            false // Not an error
        );

        $this->exporter()->completeTrace(
            $event->executionId,
            array_keys($event->finalState->toArray()),
            $durationMs,
            $finalState,
            $endedAt
        );
    }

    /**
     * Handle graph execution failed.
     */
    public function onGraphFailed(GraphExecutionFailed $event): void
    {
        if (! $this->exporter()->isEnabled()) {
            return;
        }

        $spanId = $event->spanId ?? uniqid('span_', true);
        $traceStartDt = $this->traceStart($event->executionId);
        $durationMs = $this->graphDurationMs($traceStartDt, TraceTime::now(), $event->durationMs);
        $startedAt = $traceStartDt;
        $endedAt = $startedAt ?? TraceTime::now();
        $resolvedStartedAt = $endedAt;
        $resolvedEndedAt = $endedAt;
        $this->calculateSpanTimestamps($startedAt, null, $durationMs, $traceStartDt, $resolvedStartedAt, $resolvedEndedAt);

        $this->exporter()->addSpan([
            'id' => $spanId,
            'trace_id' => $event->executionId,
            'parent_id' => null,
            'node_name' => $event->graphName,
            'node_type' => 'graph',
            'span_type' => 'graph',
            'status' => 'failed',
            'span_kind' => 'internal',
            'status_code' => 'ERROR',
            'started_at' => TraceTime::format($resolvedStartedAt),
            'ended_at' => TraceTime::format($resolvedEndedAt),
            'duration_ms' => $durationMs,
            'error' => CaptureDepth::error(get_class($event->exception), $event->exception->getMessage()),
        ]);

        // The state at the failure, for full and error_only
        $finalState = $this->captureStateData(
            $event->state->toArray(),
            $event->state->toArray(),
            true // Is an error
        );

        $this->exporter()->failTrace(
            $event->executionId,
            $event->exception,
            $durationMs,
            $finalState,
            $resolvedEndedAt
        );
    }

    /**
     * Handle node execution completed.
     */
    public function onNodeCompleted(NodeExecutionCompleted $event): void
    {
        if (! $this->exporter()->isEnabled()) {
            return;
        }

        $inputKeys = array_keys($event->inputState->toArray());
        $outputKeys = array_keys($event->outputState->toArray());

        $durationMs = $event->duration * 1000;
        $traceStartDt = $this->traceStart($event->executionId);
        $startedAt = $event->startedAt ?? $traceStartDt;
        $endedAt = $startedAt ?? TraceTime::now();
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

        $spanId = $event->spanId ?? uniqid('span_', true);
        $this->exporter()->addSpan([
            'id' => $spanId,
            'trace_id' => $event->executionId,
            'parent_id' => $event->parentSpanId,
            'node_name' => $event->nodeName,
            'node_type' => $event->metadata['node_type'] ?? null,
            'span_type' => 'node',
            'attempt' => $event->metadata['attempt'] ?? 1,
            'attempt_of' => $event->metadata['attempt_of'] ?? $spanId,
            'status' => 'success',
            'span_kind' => 'internal',
            'status_code' => 'OK',
            'started_at' => TraceTime::format($resolvedStartedAt),
            'ended_at' => TraceTime::format($resolvedEndedAt),
            'duration_ms' => $durationMs,
            'state_diff' => [
                'added' => array_values(array_diff($outputKeys, $inputKeys)),
                'removed' => array_values(array_diff($inputKeys, $outputKeys)),
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
        if (! $this->exporter()->isEnabled()) {
            return;
        }

        $traceStartDt = $this->traceStart($event->executionId);
        $startedAt = $event->startedAt;
        $endedAt = $startedAt ?? TraceTime::now();
        $resolvedStartedAt = $endedAt;
        $resolvedEndedAt = $endedAt;
        $this->calculateSpanTimestamps($startedAt, null, $event->durationMs, $traceStartDt, $resolvedStartedAt, $resolvedEndedAt);

        $spanId = $event->spanId ?? uniqid('span_', true);
        $this->exporter()->addSpan([
            'id' => $spanId,
            'trace_id' => $event->executionId,
            'parent_id' => $event->parentSpanId,
            'node_name' => $event->nodeName,
            'node_type' => $event->nodeType,
            'span_type' => 'node',
            'attempt' => $event->retryAttempt,
            'attempt_of' => $event->attemptOf ?? $spanId,
            'status' => 'failed',
            'span_kind' => 'internal',
            'status_code' => 'ERROR',
            'started_at' => TraceTime::format($resolvedStartedAt),
            'ended_at' => TraceTime::format($resolvedEndedAt),
            'duration_ms' => $event->durationMs,
            'error' => CaptureDepth::error(get_class($event->exception), $event->exception->getMessage()),
        ]);
    }

    /**
     * Handle tokens used (LLM calls).
     */
    public function onTokensUsed(TokensUsed $event): void
    {
        if (! $this->exporter()->isEnabled()) {
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

        $pricing = $this->pricingCalculator->calculate($event->model, $event->promptTokens, $event->completionTokens, $event->cacheReadTokens, $event->cacheWriteTokens);
        $cost = $pricing['cost_usd'];

        $durationMs = $event->durationMs ?? 0.0;

        $traceStartDt = $executionId ? $this->traceStart($executionId) : null;
        $eventStartedAt = $event->startedAt instanceof \DateTimeImmutable ? $event->startedAt : null;
        $eventEndedAt = $event->endedAt instanceof \DateTimeImmutable ? $event->endedAt : null;
        $startedAt = $eventStartedAt ?? $eventEndedAt ?? $traceStartDt ?? TraceTime::now();
        $endedAt = $eventEndedAt ?? $startedAt;
        $this->calculateSpanTimestamps($eventStartedAt, $eventEndedAt, $durationMs, $traceStartDt, $startedAt, $endedAt);

        $parentId = $event->parentSpanId;
        $attributes = $this->llmAttributes($event->contextCount, $event->contextIds, $event->promptFile);

        $this->exporter()->addSpan([
            'id' => $spanId ?? uniqid('span_', true),
            'trace_id' => $executionId,
            'parent_id' => $parentId,
            'node_name' => $event->node.' llm',
            'node_type' => 'llm',
            'span_type' => 'llm',
            'status' => 'success',
            'span_kind' => 'client',
            'status_code' => 'OK',
            'started_at' => TraceTime::format($startedAt),
            'ended_at' => TraceTime::format($endedAt),
            'duration_ms' => $durationMs,
            'model' => $event->model,
            'prompt_tokens' => $event->promptTokens,
            'completion_tokens' => $event->completionTokens,
            'total_tokens' => $event->totalTokens,
            'cost_usd' => $cost,
            'cached' => $event->cached,
            'streamed' => $event->streamed,
            ...($event->thoughtTokens !== null ? ['thought_tokens' => $event->thoughtTokens] : []),
            ...($event->cacheReadTokens !== null ? ['cache_read_tokens' => $event->cacheReadTokens] : []),
            ...($event->cacheWriteTokens !== null ? ['cache_write_tokens' => $event->cacheWriteTokens] : []),
            ...($attributes !== [] ? ['attributes' => $attributes] : []),
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
            'duration_ms' => $durationMs,
            'created_at' => TraceTime::format(TraceTime::now()),
            'cost_usd' => $cost,
            'streamed' => $event->streamed,
            'thought_tokens' => $event->thoughtTokens,
            'cache_read_tokens' => $event->cacheReadTokens,
            'cache_write_tokens' => $event->cacheWriteTokens,
            'prompt_name' => $event->promptFile['name'] ?? null,
            'prompt_version' => $event->promptFile['version'] ?? null,
            'prompt_hash' => $event->promptFile['hash'] ?? null,
        ];

        // Add prompt if capture is enabled
        if ($this->capturePrompts && $event->prompt !== null) {
            $llmCallData['prompt'] = $this->truncateText($event->prompt, $this->captureMaxLength);
        }

        // Add response if capture is enabled
        if ($this->captureResponses && $event->response !== null) {
            $llmCallData['response'] = $this->truncateText($event->response, $this->captureMaxLength);
        }

        if ($event->cached) {
            $llmCallData['cached'] = true;
        }

        if ($event->cacheKey !== null) {
            $llmCallData['cache_key'] = $event->cacheKey;
        }

        $this->exporter()->addLlmCall($llmCallData);
    }

    /**
     * A model call the provider failed: an llm span with status ERROR, the error, and null tokens and cost (unknown:
     * the provider may still bill it). No llm_calls row, which holds known usage only.
     */
    public function onLlmCallFailed(LlmCallFailed $event): void
    {
        if (! $this->exporter()->isEnabled()) {
            return;
        }

        $attributes = $this->llmAttributes($event->contextCount, $event->contextIds, $event->promptFile);

        $this->exporter()->addSpan([
            'id' => uniqid('span_', true),
            'trace_id' => $event->executionId ?? TraceContext::tryGet()?->getExecutionId(),
            'parent_id' => $event->parentSpanId,
            'node_name' => $event->node.' llm',
            'node_type' => 'llm',
            'span_type' => 'llm',
            'status' => 'failed',
            'span_kind' => 'client',
            'status_code' => 'ERROR',
            'started_at' => TraceTime::format($event->startedAt),
            'ended_at' => TraceTime::format(TraceTime::addMs($event->startedAt, $event->durationMs)),
            'duration_ms' => $event->durationMs,
            'model' => $event->model,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'total_tokens' => null,
            'cost_usd' => null,
            'cached' => false,
            'streamed' => $event->streamed,
            'error' => CaptureDepth::error($event->exception::class, $event->exception->getMessage()),
            ...($attributes !== [] ? ['attributes' => $attributes] : []),
        ]);
    }

    /**
     * The llm span's context and prompt file attributes; each group whole or absent.
     *
     * @param  list<string>|null  $contextIds
     * @param  array{name: string, version: string, hash: string}|null  $promptFile
     * @return array<string, mixed>
     */
    private function llmAttributes(?int $contextCount, ?array $contextIds, ?array $promptFile): array
    {
        return [
            ...($contextCount !== null ? ['context.count' => $contextCount, 'context.ids' => $contextIds ?? []] : []),
            ...($promptFile !== null ? ['prompt.name' => $promptFile['name'], 'prompt.version' => $promptFile['version'], 'prompt.hash' => $promptFile['hash']] : []),
        ];
    }

    public function onToolCallCaptured(ToolCallCaptured $event): void
    {
        if (! $this->exporter()->isEnabled() || $event->spanAlreadyExported) {
            return;
        }

        $durationMs = $event->durationMs ?? 0;
        $traceStartDt = $this->traceStart($event->executionId);
        $startedAt = $event->startedAt;
        $endedAt = $startedAt;
        $this->calculateSpanTimestamps($startedAt, null, $durationMs, $traceStartDt, $startedAt, $endedAt);
        $status = $event->status;
        $statusCode = $status === 'success' ? 'OK' : 'ERROR';
        $parentId = $event->spanId ?? null;

        $this->exporter()->addSpan([
            'id' => uniqid('span_', true),
            'trace_id' => $event->executionId,
            'parent_id' => $parentId,
            'node_name' => $event->toolName,
            'node_type' => 'tool',
            'span_type' => 'tool',
            'status' => $status,
            'span_kind' => 'internal',
            'status_code' => $statusCode,
            'started_at' => TraceTime::format($startedAt),
            'ended_at' => TraceTime::format($endedAt),
            'duration_ms' => $durationMs,
            'attributes' => [
                'gen_ai.tool.call.id' => $event->toolCallId,
                'tool.type' => $event->toolType,
                'tool.provider' => $event->toolProvider,
                'tool.request_id' => $event->toolRequestId,
                'tool.input_size' => $event->inputSize,
                'tool.output_size' => $event->outputSize,
            ],
            'error' => $event->errorType || $event->errorMessage ? CaptureDepth::error($event->errorType, $event->errorMessage) : null,
        ]);
    }

    /**
     * Handle node interrupted event.
     */
    public function onNodeInterrupted(NodeInterrupted $event): void
    {
        if (! $this->exporter()->isEnabled()) {
            return;
        }

        // The root graph span is sent now with status interrupted; completion or failure later
        // re-sends the same id in a later segment, and the merge keeps the final segment copy.
        if ($event->rootSpanId !== null && $event->graphName !== null) {
            $traceStartDt = $this->traceStart($event->executionId) ?? $event->interruptedAt;
            $this->exporter()->addSpan([
                'id' => $event->rootSpanId,
                'trace_id' => $event->executionId,
                'parent_id' => null,
                'node_name' => $event->graphName,
                'node_type' => 'graph',
                'span_type' => 'graph',
                'status' => 'interrupted',
                'span_kind' => 'internal',
                'status_code' => 'PAUSED',
                'started_at' => TraceTime::format($traceStartDt),
                'ended_at' => TraceTime::format($event->interruptedAt),
                'duration_ms' => max(TraceTime::diffMs($traceStartDt, $event->interruptedAt), 0.0),
            ]);
        }

        // Create a span for the interrupt
        $this->exporter()->addSpan([
            'id' => uniqid('span_', true),
            'trace_id' => $event->executionId,
            'parent_id' => $event->parentSpanId,
            'node_name' => $event->nodeName.' (interrupted)',
            'node_type' => 'interrupt',
            'span_type' => 'interrupt',
            'status' => 'interrupted',
            'span_kind' => 'internal',
            'status_code' => 'PAUSED',
            'started_at' => TraceTime::format($event->interruptedAt),
            'ended_at' => TraceTime::format($event->interruptedAt),
            'duration_ms' => 0, // Instant event
            'attributes' => [
                'interrupt.reason' => $event->reason,
                'checkpoint.id' => $event->checkpointId,
            ] + ($event->expiresAt !== null ? ['interrupt.expires_at' => TraceTime::format($event->expiresAt)] : []),
            'state_snapshot' => array_keys($event->stateSnapshot), // Just keys for size
        ]);
    }

    /**
     * Handle node resumed event.
     */
    public function onNodeResumed(NodeResumed $event): void
    {
        if (! $this->exporter()->isEnabled()) {
            return;
        }

        // Create a span for the resume
        $this->exporter()->addSpan([
            'id' => uniqid('span_', true),
            'trace_id' => $event->executionId,
            'parent_id' => $event->parentSpanId,
            'node_name' => $event->nodeName.' (resumed)',
            'node_type' => 'resume',
            'span_type' => 'resume',
            'status' => 'resumed',
            'span_kind' => 'internal',
            'status_code' => 'OK',
            'started_at' => TraceTime::format($event->resumedAt),
            'ended_at' => TraceTime::format($event->resumedAt),
            'duration_ms' => 0, // Instant event
            'attributes' => [
                'checkpoint.id' => $event->checkpointId,
            ],
            'state_snapshot' => array_keys($event->stateSnapshot), // Just keys for size
        ]);
    }

    /**
     * Handle a routing decision: a child of the root graph span.
     */
    public function onRouteDecided(RouteDecided $event): void
    {
        if (! $this->exporter()->isEnabled()) {
            return;
        }

        $this->exporter()->addSpan([
            'id' => $event->spanId,
            'trace_id' => $event->executionId,
            'parent_id' => $event->parentSpanId,
            'node_name' => $event->from.' route',
            'node_type' => 'route',
            'span_type' => 'route',
            'status' => 'success',
            'span_kind' => 'internal',
            'status_code' => 'OK',
            'started_at' => TraceTime::format($event->startedAt),
            'ended_at' => TraceTime::format($event->endedAt),
            'duration_ms' => TraceTime::diffMs($event->startedAt, $event->endedAt),
            'attributes' => [
                'route.from' => $event->from,
                'route.targets' => $event->targets,
                'route.router' => $event->router,
                'route.kind' => $event->kind,
                'route.from_cache' => $event->fromCache,
                'route.fallback' => $event->fallback,
                'route.reason' => null,
            ],
        ]);
    }

    /**
     * The listener is subscribed at boot for every app, so the exporter is resolved per call: an app with
     * tracing off never builds one, and a TracerExporter rebound at runtime receives the spans.
     */
    protected function exporter(): TracerExporter
    {
        return $this->exporter ?? app(TracerExporter::class);
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
            NodeExecutionCompleted::class => 'onNodeCompleted',
            NodeExecutionFailed::class => 'onNodeFailed',
            TokensUsed::class => 'onTokensUsed',
            LlmCallFailed::class => 'onLlmCallFailed',
            ToolCallCaptured::class => 'onToolCallCaptured',
            // Interrupt events
            NodeInterrupted::class => 'onNodeInterrupted',
            NodeResumed::class => 'onNodeResumed',
            RouteDecided::class => 'onRouteDecided',
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
            'full' => $this->boundState($currentState),
            'error_only' => $isError ? $this->boundState($currentState) : null,
            default => null, // keys, diff and none send no trace-level state (diff is per node)
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
            'full' => $this->boundState($outputState),
            'diff' => $this->computeDiff($inputState, $outputState),
            // error_only sends keys on success; node spans with state are always successful
            'keys', 'error_only' => $this->computeDiff($inputState, $outputState, withValues: false),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function boundState(array $state): array
    {
        return array_map(CaptureDepth::stateValue(...), $state);
    }

    /**
     * Compute difference between input and output state: per changed top-level key the bounded
     * values, or without values only the type of the new (or removed) value.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function computeDiff(array $input, array $output, bool $withValues = true): array
    {
        $diff = [];
        $entry = fn (string $type, array $values): array => $withValues
            ? ['type' => $type, ...array_map(CaptureDepth::stateValue(...), $values)]
            : ['type' => $type, 'value_type' => get_debug_type(end($values))];

        // Find added and changed keys
        foreach ($output as $key => $value) {
            if (! array_key_exists($key, $input)) {
                $diff[$key] = $entry('added', ['value' => $value]);
            } elseif ($input[$key] !== $value) {
                $diff[$key] = $entry('changed', ['old' => $input[$key], 'new' => $value]);
            }
        }

        // Find removed keys
        foreach ($input as $key => $value) {
            if (! array_key_exists($key, $output)) {
                $diff[$key] = $entry('removed', ['old' => $value]);
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

    /**
     * Graph span duration is wall time from the trace start, so a resumed or queued segment
     * still covers the whole trace. The measured segment is kept when no start is known.
     */
    private function graphDurationMs(?\DateTimeImmutable $traceStart, \DateTimeInterface $endedAt, float $measuredMs): float
    {
        return $traceStart ? max($measuredMs, TraceTime::diffMs($traceStart, $endedAt)) : $measuredMs;
    }

    private function traceStart(string $executionId): ?\DateTimeImmutable
    {
        $traceStart = $this->exporter()->getTraceStartTime($executionId);

        return $traceStart ? \DateTimeImmutable::createFromInterface($traceStart) : null;
    }

    private function calculateSpanTimestamps(
        ?\DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        float $durationMs,
        ?\DateTimeImmutable $traceStart,
        \DateTimeImmutable &$resolvedStartedAt,
        \DateTimeImmutable &$resolvedEndedAt
    ): void {
        if ($durationMs < 0) {
            Log::debug('AgenticGraph tracing: negative span duration clamped to 0', ['duration_ms' => $durationMs]);
            $durationMs = 0.0;
        }

        if ($startedAt instanceof \DateTimeImmutable) {
            $resolvedStartedAt = $startedAt;
            $resolvedEndedAt = TraceTime::addMs($startedAt, $durationMs);

            return;
        }

        if ($endedAt instanceof \DateTimeImmutable) {
            $resolvedStartedAt = TraceTime::addMs($endedAt, -$durationMs);
            $resolvedEndedAt = $endedAt;

            return;
        }

        // Use trace start as the base for calculating timestamps when event timestamps are missing
        // This ensures consistent timestamps even in async/queued scenarios
        $baseTime = $traceStart ?? TraceTime::now();
        $resolvedStartedAt = $baseTime;
        $resolvedEndedAt = TraceTime::addMs($baseTime, $durationMs);
    }
}
