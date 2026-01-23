<?php

namespace Shahzad\AgenticGraph\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\Graph;

class ExecuteNodeJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $executionId,
        public readonly string $nodeName,
        public readonly StateContract $state,
        public readonly Graph $graph,
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection'));
        $this->onQueue(config('agenticgraph.execution.queue_name'));
    }

    public function handle(): StateContract
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return $this->state; // Don't execute if batch was cancelled
        }

        try {
            $node = $this->graph->getNode($this->nodeName);
            $result = $node->execute($this->state);

            // Store with shorter TTL since it's only needed until batch completes
            $cacheKey = 'agenticgraph:batch:'.$this->executionId.':'.$this->nodeName;
            \Illuminate\Support\Facades\Cache::put($cacheKey, $result->toArray(), 300); // 5 minutes

            return $result;
        } catch (\Throwable $e) {
            // Log error with context
            \Illuminate\Support\Facades\Log::error('ExecuteNodeJob failed', [
                'execution_id' => $this->executionId,
                'node' => $this->nodeName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
