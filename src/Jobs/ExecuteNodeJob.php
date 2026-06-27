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
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class ExecuteNodeJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $stateData
     */
    public function __construct(
        public readonly string $executionId,
        public readonly string $nodeName,
        public readonly array $stateData,
        public readonly string $graphName,
        public readonly ?string $parentSpanId = null,
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection'));
        $this->onQueue(config('agenticgraph.execution.queue_name'));
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

        $spanId = $traceContext->startSpan($this->nodeName);

        // Capture precise start time when job executes (after unserialization)
        $attemptStart = microtime(true);
        $startedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $attemptStart)) ?: new \DateTimeImmutable;

        Log::debug('ExecuteNodeJob started', [
            'execution_id' => $this->executionId,
            'node' => $this->nodeName,
            'job_start_microtime' => $jobStartMicrotime,
            'attempt_start_microtime' => $attemptStart,
            'started_at' => $startedAt->format(DATE_ATOM),
            'parent_span_id' => $this->parentSpanId,
        ]);

        $node = $graph->getNode($this->nodeName);
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

            Event::dispatch(new NodeExecutionCompleted(
                $this->executionId,
                $this->nodeName,
                $inputState,
                $result,
                $duration,
                ['node_type' => $node::class],
                $spanId,
                $startedAt,
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
            $durationMs = (microtime(true) - $attemptStart) * 1000;
            $failedStartedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $attemptStart)) ?: new \DateTimeImmutable;

            Log::error('ExecuteNodeJob failed', [
                'execution_id' => $this->executionId,
                'node' => $this->nodeName,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
                'attempt' => $this->attempts(),
            ]);

            Event::dispatch(new NodeExecutionFailed(
                $this->executionId,
                $spanId,
                $this->nodeName,
                $state,
                $e,
                $durationMs,
                $this->attempts(),
                false,
                $failedStartedAt,
                $this->parentSpanId
            ));
            $node->onError($e, $state);
            throw $e;
        } finally {
            $traceContext->endSpan();
            if (app()->bound(HttpClientInstrumentation::class)) {
                app(HttpClientInstrumentation::class)->cleanupExecution($this->executionId);
            }
            app()->forgetInstance(ExecutionClock::class);
            app()->forgetInstance(TraceContext::class);
        }
    }
}
