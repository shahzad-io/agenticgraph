<?php

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
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionFailed;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class ExecuteNodeJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $executionId,
        public readonly string $nodeName,
        public readonly StateContract $state,
        public readonly Graph $graph,
        public readonly ?string $parentSpanId = null,
    ) {
        $this->onConnection(config('agenticgraph.execution.queue_connection'));
        $this->onQueue(config('agenticgraph.execution.queue_name'));
    }

    public function handle(): StateContract
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return $this->state;
        }

        TraceContext::startTrace($this->executionId);
        $spanId = TraceContext::startSpan($this->nodeName);
        $startedAt = new \DateTimeImmutable;

        $node = $this->graph->getNode($this->nodeName);
        $attemptStart = microtime(true);

        try {
            Event::dispatch(new NodeExecutionStarted(
                $this->executionId,
                $this->nodeName,
                $this->state,
                $startedAt,
                $spanId,
                $this->parentSpanId
            ));
            $node->onBefore($this->state);
            $result = $node->execute($this->state);
            $node->onAfter($this->state, $result);
            $duration = microtime(true) - $attemptStart;
            Event::dispatch(new NodeExecutionCompleted(
                $this->executionId,
                $this->nodeName,
                $this->state,
                $result,
                $duration,
                ['node_type' => $node::class],
                $spanId
            ));

            $cacheKey = 'agenticgraph:batch:'.$this->executionId.':'.$this->nodeName;
            Cache::put($cacheKey, $result->toArray(), 300);

            return $result;
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $attemptStart) * 1000;
            Event::dispatch(new NodeExecutionFailed(
                $this->executionId,
                $spanId,
                $this->nodeName,
                $this->state,
                $e,
                $durationMs,
                $this->attempts(),
                false
            ));
            $node->onError($e, $this->state);
            Log::error('ExecuteNodeJob failed', [
                'execution_id' => $this->executionId,
                'node' => $this->nodeName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            TraceContext::endSpan();
            TraceContext::endTrace();
        }
    }
}
