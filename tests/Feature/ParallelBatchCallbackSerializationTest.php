<?php

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\UnsignedSerializableClosure;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class P14Start extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class P14Branch extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

function registerP14ParallelGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', P14Start::class)
        ->addNode('branch1', P14Branch::class)
        ->addNode('branch2', P14Branch::class)
        ->addNode('end', P14Branch::class)
        ->addConditionalEdge('start', new class implements \Shahzad\AgenticGraph\Contracts\RouterInterface
        {
            public function route(StateContract $state): string|array
            {
                return ['branch1', 'branch2'];
            }

            public function getAvailableRoutes(): array
            {
                return ['branch1', 'branch2'];
            }

            public function getDefaultRoute(): ?string
            {
                return null;
            }

            public function validateRoute(string $route): bool
            {
                return in_array($route, ['branch1', 'branch2'], true);
            }
        })
        ->addJoin(['branch1', 'branch2'], 'end')
        ->addEdge('branch1', 'end')
        ->addEdge('branch2', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();

    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register($name, $graph);
}

/**
 * @return \Closure
 */
function roundTripSerializableClosure(\Closure $closure): \Closure
{
    $wrapped = new SerializableClosure($closure);
    $serialized = serialize($wrapped);
    $unserialized = unserialize($serialized);

    expect($unserialized)->toBeInstanceOf(SerializableClosure::class);

    return $unserialized->getClosure();
}

afterEach(function () {
    Mockery::close();
    Cache::flush();
});

it('cannot serialize batch callbacks that capture the live Graph object', function () {
    registerP14ParallelGraph('p14-nonserializable');
    $graph = app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->get('p14-nonserializable');

    $graphCapturing = static function (Batch $batch) use ($graph): void {
        unset($batch, $graph);
    };

    try {
        serialize(new UnsignedSerializableClosure($graphCapturing));
        expect(false)->toBeTrue('Expected graph-capturing callback serialization to fail');
    } catch (\Throwable $exception) {
        expect($exception->getMessage())->toContain('not allowed');
    }
});

it('round-trips the batch then callback and dispatches ContinueExecutionJob', function () {
    Bus::fake();
    config(['agenticgraph.checkpoint.enabled' => true]);

    registerP14ParallelGraph('p14-serializable-then');
    $executionId = 'exec-p14-then';
    Cache::put('agenticgraph:batch:'.$executionId.':branch1', (new State(['branch' => 1]))->toArray());
    Cache::put('agenticgraph:batch:'.$executionId.':branch2', (new State(['branch' => 2]))->toArray());

    $coordinator = app(ParallelExecutionCoordinator::class);
    $callbacks = $coordinator->buildBatchCallbacks(
        'p14-serializable-then',
        $executionId,
        'start',
        ['branch1', 'branch2'],
        null
    );

    $then = roundTripSerializableClosure($callbacks['then']);

    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-p14-then';
    $then($batch);

    Bus::assertDispatched(ContinueExecutionJob::class, function (ContinueExecutionJob $job) use ($executionId) {
        return $job->executionId === $executionId
            && $job->graphName === 'p14-serializable-then'
            && $job->startNode === 'end';
    });
});

it('round-trips the batch catch callback and marks the execution failed', function () {
    $executionId = 'exec-p14-catch';
    $repository = app(ExecutionRepository::class);
    $repository->queued($executionId, 'p14-serializable-catch', State::toStoragePayload(new State));
    $repository->started($executionId, 'p14-serializable-catch', State::toStoragePayload(new State));

    $coordinator = app(ParallelExecutionCoordinator::class);
    $callbacks = $coordinator->buildBatchCallbacks(
        'p14-serializable-catch',
        $executionId,
        'start',
        ['branch1', 'branch2'],
        null
    );

    $catch = roundTripSerializableClosure($callbacks['catch']);

    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-p14-catch';
    $catch($batch, new RuntimeException('batch worker failed'));

    $record = $repository->get($executionId);

    expect($record?->status)->toBe(ExecutionStatus::FAILED)
        ->and($record?->error)->toBe('Batch execution failed: batch worker failed');
});

it('round-trips the batch finally callback and cleans cached batch results', function () {
    $executionId = 'exec-p14-finally';
    Cache::put('agenticgraph:batch:'.$executionId.':branch1', (new State)->toArray());
    Cache::put('agenticgraph:batch:'.$executionId.':branch2', (new State)->toArray());

    $coordinator = app(ParallelExecutionCoordinator::class);
    $callbacks = $coordinator->buildBatchCallbacks(
        'p14-serializable-finally',
        $executionId,
        'start',
        ['branch1', 'branch2'],
        null
    );

    $finally = roundTripSerializableClosure($callbacks['finally']);

    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-p14-finally';
    $finally($batch);

    expect(Cache::get('agenticgraph:batch:'.$executionId.':branch1'))->toBeNull()
        ->and(Cache::get('agenticgraph:batch:'.$executionId.':branch2'))->toBeNull();
});

it('persists a checkpoint when the serialized then callback completes', function () {
    Bus::fake();
    config(['agenticgraph.checkpoint.enabled' => true]);

    registerP14ParallelGraph('p14-serializable-checkpoint');
    $executionId = 'exec-p14-checkpoint';
    Cache::put('agenticgraph:batch:'.$executionId.':branch1', (new State(['branch' => 1]))->toArray());
    Cache::put('agenticgraph:batch:'.$executionId.':branch2', (new State(['branch' => 2]))->toArray());

    $coordinator = app(ParallelExecutionCoordinator::class);
    $then = roundTripSerializableClosure($coordinator->buildBatchCallbacks(
        'p14-serializable-checkpoint',
        $executionId,
        'start',
        ['branch1', 'branch2'],
        null
    )['then']);

    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-p14-checkpoint';
    $then($batch);

    $checkpoint = app(StorageInterface::class)->loadLatestForExecution($executionId);

    expect($checkpoint)->not->toBeNull()
        ->and($checkpoint->executionId)->toBe($executionId)
        ->and($checkpoint->currentNode)->toBe('start');
});
