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
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;

class ExecuteGraphJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        $this->onConnection(config('agenticgraph.execution.queue_connection'));
        $this->onQueue(config('agenticgraph.execution.queue_name'));
    }

    public function uniqueId(): string
    {
        return $this->executionId;
    }

    public function handle(GraphExecutor $executor, GraphRegistry $registry): void
    {
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
        $traceContext = new \Shahzad\AgenticGraph\Tracing\TraceContext(
            executionId: $this->executionId,
            rootSpanId: $this->rootSpanId ?? uniqid('span_', true)
        );
        app()->instance(\Shahzad\AgenticGraph\Tracing\TraceContext::class, $traceContext);

        // Set up execution clock for timing
        $jobStartMicrotime = microtime(true);
        app()->instance(\Shahzad\AgenticGraph\Tracing\ExecutionClock::class, new \Shahzad\AgenticGraph\Tracing\ExecutionClock($this->executionId, $jobStartMicrotime));

        // Set up execution context for loop tracking
        $executionContext = new \Shahzad\AgenticGraph\Execution\ExecutionContext(
            executionId: $this->executionId,
            startTime: $jobStartMicrotime,
        );
        app()->instance(\Shahzad\AgenticGraph\Execution\ExecutionContext::class, $executionContext);

        try {
            $options = $this->executionOptions ?? new ExecutionOptions(ExecutionMode::ASYNC, StreamingMode::NONE);
            $options = $options->withStreaming($executor->resolveStreamingMode($options));
            if ($options->streaming !== StreamingMode::NONE) {
                $streamOptions = new StreamOptions(
                    delivery: $options->streaming->value,
                    interrupts: $options->interrupts
                );
                $session = new StreamSession(
                    streamId: $this->streamId ?? uniqid('stream_', true),
                    executionId: $this->executionId,
                    options: $streamOptions,
                    startedAtUnix: time(),
                    seq: $this->streamSeq ?? 0
                );
                app()->instance(StreamContext::class, new StreamContext($session));

                $executor->executeWithStreaming($graph->name, $state, $streamOptions, $this->executionId);
            } else {
                $executor->execute($graph, $state, $options, useBatch: true, executionId: $this->executionId);
            }
        } finally {
            // Clean up container instances
            app()->forgetInstance(\Shahzad\AgenticGraph\Tracing\TraceContext::class);
            app()->forgetInstance(\Shahzad\AgenticGraph\Tracing\ExecutionClock::class);
            app()->forgetInstance(\Shahzad\AgenticGraph\Execution\ExecutionContext::class);
            app()->forgetInstance(StreamContext::class);
        }
    }
}
