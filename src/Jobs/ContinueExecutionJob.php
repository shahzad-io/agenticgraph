<?php

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphRegistry;

class ContinueExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $graphName,
        public readonly string $executionId,
        public readonly StateContract $state,
        public readonly string $startNode
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection'));
        $this->onQueue(config('agenticgraph.execution.queue_name'));
    }

    public function handle(GraphExecutor $executor): void
    {
        $graph = GraphRegistry::get($this->graphName);
        if (! $graph) {
            return;
        }
        $executor->executeFromNode($graph, $this->state, $this->startNode, $this->executionId, useBatch: true);
    }
}
