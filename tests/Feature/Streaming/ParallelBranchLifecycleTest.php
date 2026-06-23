<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Sinks\MemorySink;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamSession;
use Shahzad\AgenticGraph\Tracing\TraceContext;

class ParallelLifecycleBranchNodeA extends AbstractNode
{
    protected ?string $name = 'branch_a';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('branch_a', true);
    }
}

class ParallelLifecycleBranchNodeB extends AbstractNode
{
    protected ?string $name = 'branch_b';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('branch_b', true);
    }
}

class ParallelLifecycleForkNode extends AbstractNode
{
    protected ?string $name = 'fork';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('forked', true);
    }
}

class ParallelLifecycleSynthesizerNode extends AbstractNode
{
    protected ?string $name = 'synthesize';

    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'merged']);

        return $state->set('synthesized', true);
    }
}

class ParallelLifecyclePassthroughNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class ParallelLifecycleBatchBranchNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('batch_branch', true);
    }
}

function parallelLifecycleFanOutRouter(): RouterInterface
{
    return new class implements RouterInterface
    {
        public function route(StateContract $state): string|array
        {
            return ['branch_a', 'branch_b'];
        }

        public function getAvailableRoutes(): array
        {
            return ['branch_a', 'branch_b'];
        }

        public function getDefaultRoute(): ?string
        {
            return null;
        }

        public function validateRoute(string $route): bool
        {
            return in_array($route, ['branch_a', 'branch_b'], true);
        }
    };
}

function registerParallelLifecycleGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('fork', ParallelLifecycleForkNode::class)
        ->addNode('branch_a', ParallelLifecycleBranchNodeA::class)
        ->addNode('branch_b', ParallelLifecycleBranchNodeB::class)
        ->addNode('synthesize', ParallelLifecycleSynthesizerNode::class)
        ->addConditionalEdge('fork', parallelLifecycleFanOutRouter())
        ->addJoin(['branch_a', 'branch_b'], 'synthesize')
        ->setEntryPoint('fork')
        ->addExitPoint('synthesize')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerParallelLifecycleSyncGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', ParallelLifecyclePassthroughNode::class)
        ->addNode('branch1', ParallelLifecycleBatchBranchNode::class)
        ->addNode('branch2', ParallelLifecycleBatchBranchNode::class)
        ->addNode('end', ParallelLifecyclePassthroughNode::class)
        ->addConditionalEdge('start', new class implements RouterInterface
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
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();

    app(GraphRegistry::class)->register($name, $graph);
}

function parallelLifecycleStreamingOptions(): ExecutionOptions
{
    return new ExecutionOptions(
        mode: ExecutionMode::SYNC,
        streaming: StreamingMode::ITERATOR,
    );
}

/**
 * @param  array<int, StreamEvent>  $events
 * @return array<string, array<int, StreamEvent>>
 */
function parallelLifecycleEventsByType(array $events): array
{
    $byType = [];
    foreach ($events as $event) {
        $byType[$event->type()][] = $event;
    }

    return $byType;
}

/**
 * @param  array<int, StreamEvent>  $events
 * @return list<string>
 */
function parallelLifecycleNodeNamesFromPayload(array $events, string $type): array
{
    return array_values(array_map(function (StreamEvent $event) {
        $payload = $event->payload();
        expect($payload)->toBeArray();

        return $payload['node_name'] ?? $event->nodeName();
    }, array_filter($events, fn (StreamEvent $event) => $event->type() === $type)));
}

it('emits node_start and node_complete for each in-process parallel branch during streaming', function () {
    registerParallelLifecycleGraph('parallel-lifecycle-stream');

    $handle = AgenticGraph::execute(
        'parallel-lifecycle-stream',
        new State,
        parallelLifecycleStreamingOptions()
    );

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $byType = parallelLifecycleEventsByType($events);

    $starts = parallelLifecycleNodeNamesFromPayload($events, 'node_start');
    $completes = parallelLifecycleNodeNamesFromPayload($events, 'node_complete');

    expect($starts)->toContain('fork', 'branch_a', 'branch_b', 'synthesize')
        ->and($completes)->toContain('fork', 'branch_a', 'branch_b', 'synthesize')
        ->and(array_count_values($starts))->toBe([
            'fork' => 1,
            'branch_a' => 1,
            'branch_b' => 1,
            'synthesize' => 1,
        ])
        ->and(array_count_values($completes))->toBe([
            'fork' => 1,
            'branch_a' => 1,
            'branch_b' => 1,
            'synthesize' => 1,
        ]);
});

it('does not emit token deltas from parallel branch nodes themselves', function () {
    registerParallelLifecycleGraph('parallel-lifecycle-no-branch-tokens');

    $handle = AgenticGraph::execute(
        'parallel-lifecycle-no-branch-tokens',
        new State,
        parallelLifecycleStreamingOptions()
    );

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $branchDeltas = array_filter(
        $events,
        fn (StreamEvent $event) => $event->type() === 'text_delta'
            && in_array($event->nodeName(), ['branch_a', 'branch_b'], true)
    );

    expect($branchDeltas)->toBeEmpty();
});

it('streams token deltas from the post-join synthesizer on the linear path', function () {
    registerParallelLifecycleGraph('parallel-lifecycle-synthesizer-streams');

    $handle = AgenticGraph::execute(
        'parallel-lifecycle-synthesizer-streams',
        new State,
        parallelLifecycleStreamingOptions()
    );

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $synthDeltas = array_values(array_filter(
        $events,
        fn (StreamEvent $event) => $event->type() === 'text_delta' && $event->nodeName() === 'synthesize'
    ));

    expect($synthDeltas)->toHaveCount(1)
        ->and($synthDeltas[0]->payload()['delta'] ?? null)->toBe('merged');
});

it('does not emit stream lifecycle for non-streaming in-process parallel runs', function () {
    registerParallelLifecycleSyncGraph('parallel-lifecycle-sync');

    $sink = new MemorySink;
    expect(app()->bound(StreamContext::class))->toBeFalse();

    Event::fake([NodeExecutionCompleted::class]);

    app(GraphExecutor::class)->execute(
        app(GraphRegistry::class)->get('parallel-lifecycle-sync'),
        new State
    );

    expect(iterator_to_array($sink->events()))->toBeEmpty();
    Event::assertDispatched(NodeExecutionCompleted::class, 4);
});

it('defers stream lifecycle for batch parallel while Laravel NodeExecutionCompleted still fires per child job', function () {
    registerParallelLifecycleSyncGraph('parallel-lifecycle-batch-deferred');

    Bus::fake();

    $sink = new MemorySink;
    $session = new StreamSession(
        streamId: 'stream-batch-deferred',
        executionId: 'exec-batch-deferred',
        options: new StreamOptions(delivery: 'iterator'),
        startedAtUnix: time()
    );
    app()->instance(StreamContext::class, new StreamContext($session, $sink));
    app()->instance(TraceContext::class, new TraceContext('exec-batch-deferred', 'root-span'));

    $graph = app(GraphRegistry::class)->get('parallel-lifecycle-batch-deferred');
    app(ParallelExecutionCoordinator::class)->executeParallelViaBatch(
        $graph,
        ['branch1', 'branch2'],
        new State,
        'exec-batch-deferred'
    );

    $streamTypes = array_map(
        fn (StreamEvent $event) => $event->type(),
        iterator_to_array($sink->events())
    );
    expect($streamTypes)->not->toContain('node_start')
        ->and($streamTypes)->not->toContain('node_complete');

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);

    app()->forgetInstance(StreamContext::class);
    app()->forgetInstance(TraceContext::class);

    Event::fake([NodeExecutionCompleted::class]);

    $job = new ExecuteNodeJob(
        'exec-batch-job',
        'branch1',
        (new State)->toArray(),
        'parallel-lifecycle-batch-deferred',
        'parent-span'
    );
    $job->handle(app(GraphRegistry::class));

    Event::assertDispatched(NodeExecutionCompleted::class);
});
