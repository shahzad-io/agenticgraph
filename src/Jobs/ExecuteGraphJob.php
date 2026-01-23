<?php

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphRegistry;

class ExecuteGraphJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $graphName,
        public readonly string $executionId,
        public readonly StateContract $state,
        public readonly ?string $checkpointId = null,
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection'));
        $this->onQueue(config('agenticgraph.execution.queue_name'));
    }

    public function uniqueId(): string
    {
        return $this->executionId;
    }

    public function handle(GraphExecutor $executor): void
    {
        $graph = GraphRegistry::get($this->graphName);
        if (! $graph) {
            return;
        }
        $executor->execute($graph, $this->state, useBatch: true, executionId: $this->executionId);
    }
}
