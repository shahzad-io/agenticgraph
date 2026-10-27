<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceTime;

class ExecuteNodeJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $stateData
     * @param  array<string, mixed>|null  $loopMetadata  Loop context at fan-out
     */
    public function __construct(
        public readonly string $executionId,
        public readonly string $nodeName,
        public readonly array $stateData,
        public readonly string $graphName,
        public readonly ?string $parentSpanId = null,
        public readonly ?array $loopMetadata = null,
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection', 'redis'));
        $this->onQueue(config('agenticgraph.execution.queue_name', 'agenticgraph'));
    }

    public function handle(GraphRegistry $registry, NodeMiddlewarePipeline $pipeline): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Get graph from registry
        $graph = $registry->get($this->graphName);
        if (! $graph) {
            throw new \RuntimeException("Graph not found: {$this->graphName}");
        }

        // Reconstruct State from array
        $state = State::fromArray($this->stateData);

        // Create trace context for this job
        $traceContext = new TraceContext(
            executionId: $this->executionId,
            rootSpanId: $this->parentSpanId ?? uniqid('span_', true)
        );
        app()->instance(TraceContext::class, $traceContext);

        // Create fresh ExecutionClock when job executes (not serialized)
        // This ensures timestamps are relative to when job actually runs
        $jobStartMicrotime = microtime(true);
        app()->instance(ExecutionClock::class, new ExecutionClock($this->executionId, $jobStartMicrotime));

        // Jobs queued before this property existed leave it uninitialized; ?? reads that as null
        // and the branch runs without a loop context, as before.
        $loopMetadata = $this->loopMetadata ?? null;
        $previousLoopContext = app()->bound(ExecutionContext::class) ? app(ExecutionContext::class) : null;
        if ($loopMetadata !== null) {
            app()->instance(ExecutionContext::class, ExecutionContext::fromArray($loopMetadata, $this->executionId)->pushNode($this->nodeName));
        }

        $spanId = $traceContext->startSpan($this->nodeName);

        // Capture precise start time when job executes (after unserialization)
        $attemptStart = microtime(true);
        $startedAt = TraceTime::fromMicrotime($attemptStart);

        // Each job sends its spans as its own segment of the parent trace.
        $exporter = app()->bound(TracerExporter::class) ? app(TracerExporter::class) : null;
        if ($exporter?->isEnabled()) {
            $exporter->resumeTrace($this->executionId, $this->graphName, 'continued', $startedAt);
        }

        Log::debug('ExecuteNodeJob started', [
            'execution_id' => $this->executionId,
            'node' => $this->nodeName,
            'job_start_microtime' => $jobStartMicrotime,
            'attempt_start_microtime' => $attemptStart,
            'started_at' => $startedAt->format(DATE_ATOM),
            'parent_span_id' => $this->parentSpanId,
        ]);

        $node = $graph->getNode($this->nodeName);
        // Node annotations read the graph of the running execution.
        $previousGraph = app()->bound(Graph::class) ? app(Graph::class) : null;
        app()->instance(Graph::class, $graph);
        $inputState = $state;

        try {
            Event::dispatch(new NodeExecutionStarted(
                $this->executionId,
                $this->nodeName,
                $state,
                $startedAt,
                $spanId,
                $this->parentSpanId
            ));

            $result = $pipeline->execute($state, $node, $this->executionId, $this->nodeName, $spanId);
            $duration = microtime(true) - $attemptStart;
            // After retries, the completed span describes the successful attempt only.
            $attempt = $pipeline->lastAttempt();
            $successStart = $attempt['startedAt'] ?? $attemptStart;

            Event::dispatch(new NodeExecutionCompleted(
                $this->executionId,
                $this->nodeName,
                $inputState,
                $result,
                microtime(true) - $successStart,
                ['node_type' => $node::class, 'attempt' => $attempt['attempt'] ?? 1, 'attempt_of' => $spanId],
                $attempt['spanId'] ?? $spanId,
                TraceTime::fromMicrotime($successStart),
                $this->parentSpanId
            ));

            $cacheKey = 'agenticgraph:batch:'.$this->executionId.':'.$this->nodeName;
            Cache::put($cacheKey, $result->toArray(), 300);

            $endMicrotime = microtime(true);
            Log::debug('ExecuteNodeJob completed', [
                'execution_id' => $this->executionId,
                'node' => $this->nodeName,
                'duration_seconds' => $duration,
                'attempt_start' => $attemptStart,
                'attempt_end' => $endMicrotime,
                'started_at' => $startedAt->format(DATE_ATOM),
            ]);
        } catch (\Throwable $e) {
            // The pipeline already dispatched NodeExecutionFailed and called onError for each attempt.
            Log::error('ExecuteNodeJob failed', [
                'execution_id' => $this->executionId,
                'node' => $this->nodeName,
                'error' => $e->getMessage(),
                'duration_ms' => (microtime(true) - $attemptStart) * 1000,
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        } finally {
            $traceContext->endSpan();
            if ($exporter?->isEnabled()) {
                $exporter->flush($this->executionId);
            }
            if (app()->bound(HttpClientInstrumentation::class)) {
                app(HttpClientInstrumentation::class)->cleanupExecution($this->executionId);
            }
            app()->forgetInstance(ExecutionClock::class);
            app()->forgetInstance(TraceContext::class);
            $previousGraph !== null ? app()->instance(Graph::class, $previousGraph) : app()->forgetInstance(Graph::class);
            if ($loopMetadata !== null) {
                $previousLoopContext !== null
                    ? app()->instance(ExecutionContext::class, $previousLoopContext)
                    : app()->forgetInstance(ExecutionContext::class);
            }
        }
    }
}
