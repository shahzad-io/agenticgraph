<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumePayload;
use Shahzad\AgenticGraph\Streaming\StreamSession;

class ContinueExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $stateData
     * @param  array<string, mixed>|null  $loopMetadata
     */
    public function __construct(
        public readonly string $graphName,
        public readonly string $executionId,
        public readonly array $stateData,  // ✅ Plain array instead of StateContract
        public readonly string $startNode,
        public readonly ?array $loopMetadata = null,  // Loop context for resume
        public readonly ?ExecutionOptions $executionOptions = null,
        public readonly ?string $streamId = null,
        public readonly ?int $streamSeq = null,
        public readonly ?StreamResumePayload $resumePayload = null,
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection'));
        $this->onQueue(config('agenticgraph.execution.queue_name'));
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

        // Restore loop metadata if available
        $loopMeta = $this->loopMetadata !== null
            ? LoopMetadata::fromArray($this->loopMetadata)
            : null;

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

                $executor->executeWithStreamingFromNode(
                    $graph,
                    $state,
                    $this->startNode,
                    $this->executionId,
                    $streamOptions,
                    $loopMeta,
                    $this->resumePayload
                );
            } else {
                $executor->executeFromNode($graph, $state, $this->startNode, $this->executionId, useBatch: true, loopMetadata: $loopMeta);
            }
        } finally {
            app()->forgetInstance(StreamContext::class);
        }
    }
}
