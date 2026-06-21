<?php

use Illuminate\Bus\Batch;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shahzad\AgenticGraph\Contracts\ParallelExecutorInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\RouterResolverInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\RoutingEngine;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\RetentionPolicyFactory;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamErrorHandler;
use Shahzad\AgenticGraph\Streaming\StreamingExecutionCoordinator;
use Shahzad\AgenticGraph\Streaming\StreamObserverFactory;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Streaming\StreamSinkFactory;
use Shahzad\AgenticGraph\Tracing\ExecutionClockFactory;
use Shahzad\AgenticGraph\Tracing\TraceContextFactory;

class ANode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('a', true);
    }
}

class BNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('b', true);
    }
}

class CNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('c', true);
    }
}

class DirectRouter implements RouterInterface
{
    public function route(StateContract $state): string|array
    {
        return 'b';
    }

    public function getAvailableRoutes(): array
    {
        return ['b'];
    }

    public function getDefaultRoute(): ?string
    {
        return null;
    }

    public function validateRoute(string $route): bool
    {
        return $route === 'b';
    }
}

class ClassRouter extends DirectRouter {}

afterEach(function () {
    Mockery::close();
});

it('computes post-parallel next using joins', function () {
    $graph = GraphBuilder::create('join')
        ->addNode('start', ANode::class)
        ->addNode('a', ANode::class)
        ->addNode('b', BNode::class)
        ->addNode('c', CNode::class)
        ->addConditionalEdge('start', new class implements \Shahzad\AgenticGraph\Contracts\RouterInterface
        {
            public function route(\Shahzad\AgenticGraph\Contracts\StateContract $state): string|array
            {
                return ['a', 'b'];
            }

            public function getAvailableRoutes(): array
            {
                return ['a', 'b'];
            }

            public function getDefaultRoute(): ?string
            {
                return null;
            }

            public function validateRoute(string $route): bool
            {
                return in_array($route, ['a', 'b'], true);
            }
        })
        ->addJoin(['a', 'b'], 'c')
        ->addEdge('a', 'c')
        ->addEdge('b', 'c')
        ->setEntryPoint('start')
        ->addExitPoint('c')
        ->build();
    $state = new State;
    $routingEngine = new RoutingEngine(app());
    $next = $routingEngine->determinePostParallelNext($graph, ['a', 'b'], $state);
    expect($next)->toBe('c');
});

it('returns direct edge target when no router exists', function () {
    $graph = GraphBuilder::create('linear')
        ->addNode('start', ANode::class)
        ->addNode('end', BNode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    $executor = new GraphExecutor;
    $state = new State;
    $next = (new ReflectionClass($executor))->getMethod('determineNext')->invoke($executor, $graph, 'start', $state);
    expect($next)->toBe('end');
});

it('uses router instance to determine next', function () {
    $graph = GraphBuilder::create('router-instance')
        ->addNode('start', ANode::class)
        ->addNode('b', BNode::class)
        ->addConditionalEdge('start', new DirectRouter)
        ->addEdge('start', 'b')
        ->setEntryPoint('start')
        ->addExitPoint('b')
        ->build();
    $executor = new GraphExecutor;
    $state = new State;
    $next = (new ReflectionClass($executor))->getMethod('determineNext')->invoke($executor, $graph, 'start', $state);
    expect($next)->toBe('b');
});

it('uses router class string to determine next', function () {
    $graph = GraphBuilder::create('router-class')
        ->addNode('start', ANode::class)
        ->addNode('b', BNode::class)
        ->addConditionalEdge('start', ClassRouter::class)
        ->addEdge('start', 'b')
        ->setEntryPoint('start')
        ->addExitPoint('b')
        ->build();
    $executor = new GraphExecutor;
    $state = new State;
    $next = (new ReflectionClass($executor))->getMethod('determineNext')->invoke($executor, $graph, 'start', $state);
    expect($next)->toBe('b');
});

it('returns null when no edges exist', function () {
    $graph = GraphBuilder::create('no-edges')
        ->addNode('start', ANode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();
    $executor = new GraphExecutor;
    $state = new State;
    $next = (new ReflectionClass($executor))->getMethod('determineNext')->invoke($executor, $graph, 'start', $state);
    expect($next)->toBeNull();
});

it('returns null for post-parallel next without join', function () {
    $graph = GraphBuilder::create('no-join')
        ->addNode('start', ANode::class)
        ->addNode('a', ANode::class)
        ->addNode('b', BNode::class)
        ->addEdge('start', 'a')
        ->addEdge('start', 'b')
        ->setEntryPoint('start')
        ->addExitPoint('a')
        ->addExitPoint('b')
        ->build();
    $routingEngine = new RoutingEngine(app());
    $state = new State;
    $next = $routingEngine->determinePostParallelNext($graph, ['a', 'b'], $state);
    expect($next)->toBeNull();
});

it('builds batch result cache key', function () {
    $coordinator = new ParallelExecutionCoordinator(
        new RoutingEngine(app()),
        Mockery::mock(StateManager::class),
        Mockery::mock(CacheManager::class),
        Mockery::mock(BusDispatcher::class),
        Mockery::mock(LoggerInterface::class),
        Mockery::mock(ExecutionRepository::class),
        Mockery::mock(Dispatcher::class),
        Mockery::mock(Container::class),
        Mockery::mock(CheckpointManager::class),
        [],
        static fn () => null,
        static fn () => null,
        static fn ($job) => $job
    );
    $method = (new ReflectionClass($coordinator))->getMethod('batchResultCacheKey');
    $method->setAccessible(true);
    $key = $method->invoke($coordinator, 'exec-1', 'node-a');
    expect($key)->toBe('agenticgraph:batch:exec-1:node-a');
});

it('emits lifecycle events with stream metadata', function () {
    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        Mockery::mock(StreamStoreInterface::class),
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler
    );
    $executor = new StreamingExecutionCoordinator(
        Mockery::mock(Dispatcher::class),
        Mockery::mock(CacheManager::class),
        Mockery::mock(LoggerInterface::class),
        Mockery::mock(ExecutionRepository::class),
        Mockery::mock(ParallelExecutorInterface::class),
        Mockery::mock(RouterResolverInterface::class),
        $factory,
        Mockery::mock(Container::class),
        null,
        new ExecutionContextManager(
            null,
            null,
            new TraceContextFactory,
            new ExecutionClockFactory,
            app(),
            new NullLogger
        )
    );
    $sink = new MemorySink;
    $options = new StreamOptions(delivery: 'sse');
    $session = new StreamSession(
        streamId: 'stream-1',
        executionId: 'exec-1',
        options: $options,
        startedAtUnix: time()
    );

    $executor->emitLifecycleEvent($sink, $session, null, 'span-1', null, 'graph_start', ['graph' => 'demo']);
    $executor->emitLifecycleEvent($sink, $session, 'node-a', 'span-2', 'span-1', 'node_complete', ['node' => 'a'], 2);

    $events = iterator_to_array($sink->events());
    $first = $events[0];
    $second = $events[1];

    expect($events)->toHaveCount(2)
        ->and($first->type())->toBe('graph_start')
        ->and($first->seq())->toBe(1)
        ->and($first->payload()['stream']['event_type'])->toBe('graph_start')
        ->and($second->type())->toBe('node_complete')
        ->and($second->seq())->toBe(2)
        ->and($second->nodeSeq())->toBe(2)
        ->and($second->payload()['stream']['node_seq'])->toBe(2);
});

it('increments node sequence per node', function () {
    $factory = new StreamSinkFactory(
        Mockery::mock(Broadcaster::class),
        Mockery::mock(StreamStoreInterface::class),
        false,
        100,
        false,
        new RetentionPolicyFactory,
        new StreamObserverFactory,
        new StreamErrorHandler
    );
    $executor = new StreamingExecutionCoordinator(
        Mockery::mock(Dispatcher::class),
        Mockery::mock(CacheManager::class),
        Mockery::mock(LoggerInterface::class),
        Mockery::mock(ExecutionRepository::class),
        Mockery::mock(ParallelExecutorInterface::class),
        Mockery::mock(RouterResolverInterface::class),
        $factory,
        Mockery::mock(Container::class),
        null,
        new ExecutionContextManager(
            null,
            null,
            new TraceContextFactory,
            new ExecutionClockFactory,
            app(),
            new NullLogger
        )
    );
    $method = new ReflectionMethod(StreamingExecutionCoordinator::class, 'nextNodeSeq');
    $method->setAccessible(true);

    $nodeSeqs = new stdClass;

    $first = $method->invoke($executor, $nodeSeqs, 'node-a');
    $second = $method->invoke($executor, $nodeSeqs, 'node-a');
    $third = $method->invoke($executor, $nodeSeqs, 'node-b');

    expect($first)->toBe(1)
        ->and($second)->toBe(2)
        ->and($third)->toBe(1);
});

it('marks execution failed and logs on batch failure', function () {
    config(['agenticgraph.logging.enabled' => true]);
    $logger = Mockery::mock(LoggerInterface::class);
    $repo = Mockery::mock(ExecutionRepository::class);
    $executionId = 'exec-fail';
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-1';
    $error = new RuntimeException('boom');

    $repo->shouldReceive('failed')
        ->once()
        ->with($executionId, 'Batch execution failed: '.$error->getMessage());

    $logger->shouldReceive('error')
        ->once()
        ->with('Batch execution failed', Mockery::on(function (array $context) use ($executionId, $batch, $error) {
            return ($context['execution_id'] ?? null) === $executionId
                && ($context['batch_id'] ?? null) === $batch->id
                && ($context['error'] ?? null) === $error->getMessage();
        }));

    $coordinator = new ParallelExecutionCoordinator(
        new RoutingEngine(app()),
        Mockery::mock(StateManager::class),
        Mockery::mock(CacheManager::class),
        Mockery::mock(BusDispatcher::class),
        $logger,
        $repo,
        Mockery::mock(Dispatcher::class),
        Mockery::mock(Container::class),
        Mockery::mock(CheckpointManager::class),
        [],
        static fn () => null,
        static fn () => null,
        static fn ($job) => $job
    );
    $method = (new ReflectionClass($coordinator))->getMethod('handleBatchFailure');
    $method->setAccessible(true);
    $method->invoke($coordinator, $batch, $executionId, $error);
});
