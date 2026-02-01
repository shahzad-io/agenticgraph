<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\State\State;

class ContinueExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $stateData
     */
    public function __construct(
        public readonly string $graphName,
        public readonly string $executionId,
        public readonly array $stateData,  // ✅ Plain array instead of StateContract
        public readonly string $startNode
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection'));
        $this->onQueue(config('agenticgraph.execution.queue_name'));
    }

    public function handle(GraphExecutor $executor, GraphRegistry $registry): void
    {
        $graph = $registry->get($this->graphName);
        if (! $graph) {
            return;
        }

        // Reconstruct State from array
        $state = State::fromArray($this->stateData);

        $executor->executeFromNode($graph, $state, $this->startNode, $this->executionId, useBatch: true);
    }
}
