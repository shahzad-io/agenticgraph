<?php

use Illuminate\Bus\Batch;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\RoutingEngine;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;

class PMCBaseNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class PMCBranchAlphaNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('result', 'alpha');
    }
}

class PMCBranchBetaNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('result', 'beta');
    }
}

class PMCBranchSameNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('result', 'same');
    }
}

class PMCTechnicalNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('technical_draft', 'tech');
    }
}

class PMCMarketNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('market_draft', 'market');
    }
}

class PMCExistingNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('branch_only', 'value');
    }
}

function buildParallelCollisionGraph(string $name, string $branchANode, string $branchBNode): Graph
{
    return GraphBuilder::create($name)
        ->addNode('branch_a', $branchANode)
        ->addNode('branch_b', $branchBNode)
        ->addEdge('branch_a', 'branch_b')
        ->setEntryPoint('branch_a')
        ->addExitPoint('branch_b')
        ->build();
}

function makeParallelCollisionCoordinator(LoggerInterface $logger): ParallelExecutionCoordinator
{
    return new ParallelExecutionCoordinator(
        new RoutingEngine(app()),
        app(StateManager::class),
        app(CacheManager::class),
        app(BusDispatcher::class),
        $logger,
        app(ExecutionRepository::class),
        app(Dispatcher::class),
        app(Container::class),
        app(CheckpointManager::class),
        config('agenticgraph.middleware.global'),
        static fn () => null,
        static fn () => null,
        static fn ($job) => dispatch($job)
    );
}

afterEach(function () {
    Mockery::close();
    Cache::flush();
});

it('warns on in-process parallel merge when branches clobber the same key', function () {
    config()->set('agenticgraph.logging.enabled', true);

    $graph = buildParallelCollisionGraph(
        'pmc-in-process-clobber',
        PMCBranchAlphaNode::class,
        PMCBranchBetaNode::class,
    );

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->once()
        ->with(
            Mockery::type('string'),
            Mockery::on(fn (array $context) => ($context['key'] ?? null) === 'result'
                && ($context['colliding_branches'] ?? null) === ['branch_a', 'branch_b'])
        );

    $coordinator = makeParallelCollisionCoordinator($logger);
    $result = $coordinator->executeParallel(
        $graph,
        ['branch_a', 'branch_b'],
        new State(['topic' => 'demo']),
        'exec-pmc-in-process',
    );

    expect($result->state->get('result'))->toBe('beta');
});

it('does not warn on in-process merge when branches write the same value to the same key', function () {
    config()->set('agenticgraph.logging.enabled', true);

    $graph = buildParallelCollisionGraph(
        'pmc-in-process-same-value',
        PMCBranchSameNode::class,
        PMCBranchSameNode::class,
    );

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->never();

    $coordinator = makeParallelCollisionCoordinator($logger);
    $result = $coordinator->executeParallel(
        $graph,
        ['branch_a', 'branch_b'],
        new State(['topic' => 'demo']),
        'exec-pmc-same-value',
    );

    expect($result->state->get('result'))->toBe('same');
});

it('does not warn on in-process merge when branches write unique keys', function () {
    config()->set('agenticgraph.logging.enabled', true);

    $graph = GraphBuilder::create('pmc-unique-keys')
        ->addNode('technical', PMCTechnicalNode::class)
        ->addNode('market', PMCMarketNode::class)
        ->addEdge('technical', 'market')
        ->setEntryPoint('technical')
        ->addExitPoint('market')
        ->build();

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->never();

    $coordinator = makeParallelCollisionCoordinator($logger);
    $result = $coordinator->executeParallel(
        $graph,
        ['technical', 'market'],
        new State(['topic' => 'demo']),
        'exec-pmc-unique-keys',
    );

    expect($result->state->get('technical_draft'))->toBe('tech')
        ->and($result->state->get('market_draft'))->toBe('market');
});

it('does not warn on in-process merge for pre-existing keys left unchanged', function () {
    config()->set('agenticgraph.logging.enabled', true);

    $graph = buildParallelCollisionGraph(
        'pmc-pre-existing',
        PMCExistingNode::class,
        PMCExistingNode::class,
    );

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->never();

    $coordinator = makeParallelCollisionCoordinator($logger);
    $result = $coordinator->executeParallel(
        $graph,
        ['branch_a', 'branch_b'],
        new State(['existing' => 'unchanged']),
        'exec-pmc-pre-existing',
    );

    expect($result->state->get('existing'))->toBe('unchanged')
        ->and($result->state->get('branch_only'))->toBe('value');
});

it('warns on batch-completion merge when branches clobber the same key', function () {
    Bus::fake();
    config()->set('agenticgraph.logging.enabled', true);
    config()->set('agenticgraph.checkpoint.enabled', false);

    $graph = GraphBuilder::create('pmc-batch-clobber')
        ->addNode('branch_a', PMCBranchAlphaNode::class)
        ->addNode('branch_b', PMCBranchBetaNode::class)
        ->addNode('end', PMCBaseNode::class)
        ->addEdge('branch_a', 'branch_b')
        ->addEdge('branch_b', 'end')
        ->addJoin(['branch_a', 'branch_b'], 'end')
        ->setEntryPoint('branch_a')
        ->addExitPoint('end')
        ->build();

    app(GraphRegistry::class)->register('pmc-batch-clobber', $graph);

    $executionId = 'exec-pmc-batch-clobber';
    $base = new State(['topic' => 'demo']);
    Cache::put('agenticgraph:batch:'.$executionId.':input', $base->toArray(), 300);
    Cache::put('agenticgraph:batch:'.$executionId.':branch_a', (new State(['topic' => 'demo', 'result' => 'alpha']))->toArray(), 300);
    Cache::put('agenticgraph:batch:'.$executionId.':branch_b', (new State(['topic' => 'demo', 'result' => 'beta']))->toArray(), 300);

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->once()
        ->with(
            Mockery::type('string'),
            Mockery::on(fn (array $context) => ($context['key'] ?? null) === 'result'
                && ($context['kept_branch'] ?? null) === 'branch_b')
        );

    $coordinator = makeParallelCollisionCoordinator($logger);
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-pmc-1';

    $method = new ReflectionMethod($coordinator, 'handleBatchCompletion');
    $method->setAccessible(true);
    $method->invoke(
        $coordinator,
        $batch,
        'pmc-batch-clobber',
        $executionId,
        'branch_a',
        ['branch_a', 'branch_b'],
        null,
    );

    Bus::assertDispatched(ContinueExecutionJob::class);
});
