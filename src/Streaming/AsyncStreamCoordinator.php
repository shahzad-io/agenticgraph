<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Streaming;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\State\State;

final class AsyncStreamCoordinator
{
    public function __construct(
        private readonly GraphRegistry $registry,
        private readonly ExecutionRepository $repository
    ) {}

    /**
     * @return array{executionId: string, streamId: string}
     */
    public function streamAsyncWorkflow(string $name, StateContract $state, ExecutionOptions $options): array
    {
        $state->validate();

        $graph = $this->registry->getOrBuild($name);
        if ($graph === null) {
            throw new \RuntimeException(
                "Graph '{$name}' not found in registry or config. ".
                "Please register the graph manually or add it to config/agenticgraph.php 'graphs' array."
            );
        }

        $executionId = $this->generateExecutionId();
        $streamId = uniqid('stream_', true);
        $rootSpanId = uniqid('span_', true);

        $statePayload = State::toStoragePayload($state);

        $this->repository->queued($executionId, $graph->name, $statePayload, $streamId);

        dispatch(new ExecuteGraphJob(
            graphName: $graph->name,
            executionId: $executionId,
            stateData: $statePayload,
            checkpointId: null,
            rootSpanId: $rootSpanId,
            executionOptions: $options,
            streamId: $streamId,
            streamSeq: 0
        ));

        return [
            'executionId' => $executionId,
            'streamId' => $streamId,
        ];
    }

    private function generateExecutionId(): string
    {
        $prefix = 'exec_';
        $timestamp = microtime(true);
        $random = bin2hex(random_bytes(8));

        return $prefix.$timestamp.'_'.$random;
    }
}
