<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\State\State;

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

        $executor->executeFromNode($graph, $state, $this->startNode, $this->executionId, useBatch: true, loopMetadata: $loopMeta);
    }
}
