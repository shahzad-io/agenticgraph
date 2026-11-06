<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class ExecuteGraphJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, EndsFailedExecution, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    /** Seconds; null leaves the worker's --timeout in charge. */
    public ?int $timeout;

    /**
     * @param  array<string, mixed>  $stateData
     */
    public function __construct(
        public readonly string $graphName,
        public readonly string $executionId,
        public readonly array $stateData,  // ✅ Plain array instead of StateContract
        public readonly ?string $checkpointId = null,
        public readonly ?string $rootSpanId = null,
        public readonly ?ExecutionOptions $executionOptions = null,
        public readonly ?string $streamId = null,
        public readonly ?int $streamSeq = null,
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection', 'redis'));
        $this->onQueue(config('agenticgraph.execution.queue_name', 'agenticgraph'));
        $this->tries = max(1, (int) config('agenticgraph.execution.tries', 1));
        $this->timeout = ((int) config('agenticgraph.execution.timeout', 0)) ?: null;
    }

    public function uniqueId(): string
    {
        return $this->executionId;
    }

    public function handle(GraphExecutor $executor, GraphRegistry $registry): void
    {
        // A first attempt on an execution that already ended (expired while this job waited in the queue) must not
        // run: started() would write running over failed. Retries (attempts > 1) are unchanged.
        if ($this->attempts() === 1 && app(ExecutionRepository::class)->hasEnded($this->executionId)) {
            Log::info('AgenticGraph: job skipped, execution already ended', ['execution_id' => $this->executionId]);

            return;
        }

        // Auto-build graph from config if not cached (supports async workflows)
        $graph = $registry->getOrBuild($this->graphName);

        if (! $graph) {
            throw new \RuntimeException(
                "Graph '{$this->graphName}' not found in registry or config. ".
                'Ensure the graph is registered or added to config/agenticgraph.php graphs array.'
            );
        }

        // Reconstruct State from array
        $state = State::fromArray($this->stateData);

        // Set up tracing context for this job execution
        // This ensures all events dispatched during execution have proper trace context
        $traceContext = new TraceContext(
            executionId: $this->executionId,
            rootSpanId: $this->rootSpanId ?? uniqid('span_', true)
        );
        app()->instance(TraceContext::class, $traceContext);

        // Set up execution clock for timing
        $jobStartMicrotime = microtime(true);
        app()->instance(ExecutionClock::class, new ExecutionClock($this->executionId, $jobStartMicrotime));

        // Set up execution context for loop tracking
        $executionContext = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $jobStartMicrotime,
        );
        app()->instance(ExecutionContext::class, $executionContext);

        try {
            $options = $this->executionOptions ?? new ExecutionOptions(ExecutionMode::ASYNC, StreamingMode::NONE);
            $options = $options->withStreaming($executor->resolveStreamingMode($options));
            if ($options->streaming !== StreamingMode::NONE) {
                $streamOptions = StreamOptions::fromExecution(
                    $options,
                    delivery: $options->streaming->value,
                );
                $session = new StreamSession(
                    streamId: $this->streamId ?? uniqid('stream_', true),
                    executionId: $this->executionId,
                    options: $streamOptions,
                    startedAtUnix: time(),
                    seq: $this->streamSeq ?? 0
                );
                app()->instance(StreamContext::class, new StreamContext($session));

                $executor->executeWithStreaming($graph->name, $state, $streamOptions, $this->executionId, $options);
            } else {
                $executor->execute($graph, $state, $options, useBatch: true, executionId: $this->executionId);
            }
        } finally {
            // Clean up container instances
            app()->forgetInstance(TraceContext::class);
            app()->forgetInstance(ExecutionClock::class);
            app()->forgetInstance(ExecutionContext::class);
            app()->forgetInstance(StreamContext::class);
        }
    }

    /**
     * Ends the execution and its stream when the job fails, for example when the graph is
     * not registered on the worker.
     */
    public function failed(\Throwable $e): void
    {
        $this->endFailedExecution(
            $e,
            $this->executionId,
            $this->graphName,
            $this->stateData,
            $this->streamId,
            $this->executionOptions?->streaming,
            $this->executionOptions,
            $this->rootSpanId,
            $this->streamSeq,
        );
    }
}
