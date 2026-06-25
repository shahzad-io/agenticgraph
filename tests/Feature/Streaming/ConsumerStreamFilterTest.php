<?php

use Illuminate\Support\Facades\Bus;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;
use Shahzad\AgenticGraph\Streaming\StreamOptions;

class FilterStreamNodeA extends AbstractNode
{
    protected ?string $name = 'a';

    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'a']);

        return $state->set('a_ran', true);
    }
}

class FilterStreamNodeB extends FilterStreamNodeA
{
    protected ?string $name = 'b';

    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'b']);

        return $state->set('b_ran', true);
    }
}

class FilterStreamNodeC extends FilterStreamNodeA
{
    protected ?string $name = 'c';

    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'c']);

        return $state->set('c_ran', true);
    }
}

class FilterInterruptStreamNodeB extends PrismNode
{
    protected ?string $name = 'b';

    public function execute(StateContract $state): StateContract
    {
        $this->streamEvent('text_delta', ['delta' => 'b']);

        return $state->set('b_ran', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->get('b_passed', false);
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Pause at b';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $state->set('b_passed', true);
    }
}

function registerFilterStreamGraph(string $name, bool $interruptAtB = false): void
{
    $bNode = $interruptAtB ? FilterInterruptStreamNodeB::class : FilterStreamNodeB::class;

    $graph = GraphBuilder::create($name)
        ->addNode('a', FilterStreamNodeA::class)
        ->addNode('b', $bNode)
        ->addNode('c', FilterStreamNodeC::class)
        ->addEdge('a', 'b')
        ->addEdge('b', 'c')
        ->setEntryPoint('a')
        ->addExitPoint('c')
        ->build();

    AgenticGraph::register($name, $graph);
}

/**
 * @return list<string>
 */
function textDeltaNodeNamesFromInline(?Iterator $inline): array
{
    $events = iterator_to_array($inline ?? new ArrayIterator([]));

    return array_values(array_map(
        static fn ($event) => (string) $event->nodeName(),
        array_filter(
            $events,
            static fn ($event) => $event->type() === 'text_delta' && $event->nodeName() !== null
        )
    ));
}

/**
 * @return list<string>
 */
function textDeltaEventTypesFromInline(?Iterator $inline): array
{
    $events = iterator_to_array($inline ?? new ArrayIterator([]));

    return array_values(array_map(
        static fn ($event) => $event->type(),
        array_filter($events, static fn ($event) => $event->nodeName() === 'a')
    ));
}

/**
 * @return list<string>
 */
function textDeltaNodeNamesFromStore(?string $streamId): array
{
    if ($streamId === null || $streamId === '') {
        return [];
    }

    $store = app(StreamStoreInterface::class);
    $events = iterator_to_array($store->readFrom($streamId, 1, 1000));

    return array_values(array_map(
        static fn ($event) => (string) $event->nodeName(),
        array_filter(
            $events,
            static fn ($event) => $event->type() === 'text_delta' && $event->nodeName() !== null
        )
    ));
}

function runAsyncFilterJob(string $executionId): ExecuteGraphJob
{
    $job = null;

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use ($executionId, &$job): bool {
        if ($dispatched->executionId !== $executionId) {
            return false;
        }

        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ExecuteGraphJob::class);
    $job->handle(app(GraphExecutor::class), app(GraphRegistry::class));

    return $job;
}

beforeEach(function () {
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
});

it('delivers only filtered sync inline node events while all nodes execute', function () {
    registerFilterStreamGraph('filter-sync-inline');

    $handle = AgenticGraph::stream(
        'filter-sync-inline',
        new State,
        nodes: ['a', 'c'],
        inline: true,
    );

    $nodeNames = textDeltaNodeNamesFromInline($handle->stream()->inline);
    $finalState = $handle->getFinalState();

    expect($nodeNames)->toBe(['a', 'c'])
        ->and($finalState->get('a_ran'))->toBeTrue()
        ->and($finalState->get('b_ran'))->toBeTrue()
        ->and($finalState->get('c_ran'))->toBeTrue();
});

it('delivers all sync inline node events when no filter is set', function () {
    registerFilterStreamGraph('filter-sync-unfiltered');

    $handle = AgenticGraph::stream('filter-sync-unfiltered', new State, inline: true);

    $nodeNames = textDeltaNodeNamesFromInline($handle->stream()->inline);

    expect($nodeNames)->toBe(['a', 'b', 'c']);
});

it('applies node filter on async streaming from persisted execution options', function () {
    Bus::fake();
    registerFilterStreamGraph('filter-async-nodes');

    $handle = AgenticGraph::stream(
        'filter-async-nodes',
        new State,
        nodes: ['a', 'c'],
    );

    runAsyncFilterJob($handle->id());

    $nodeNames = textDeltaNodeNamesFromStore($handle->streamId());
    $finalState = AgenticGraph::getExecution($handle->id())->refresh()->getFinalState();

    expect($nodeNames)->toBe(['a', 'c'])
        ->and($finalState->get('a_ran'))->toBeTrue()
        ->and($finalState->get('b_ran'))->toBeTrue()
        ->and($finalState->get('c_ran'))->toBeTrue();
});

it('delivers node_complete lifecycle events when event type filter is set', function () {
    registerFilterStreamGraph('filter-node-complete-lifecycle');

    $handle = AgenticGraph::stream(
        'filter-node-complete-lifecycle',
        new State,
        events: [StreamLifecycleEventTypes::NODE_COMPLETE],
        inline: true,
    );

    $events = iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]));
    $types = array_map(static fn ($event) => $event->type(), $events);

    expect($types)->not->toBeEmpty()
        ->and($types)->toContain(StreamLifecycleEventTypes::NODE_COMPLETE)
        ->and(array_unique($types))->toBe([StreamLifecycleEventTypes::NODE_COMPLETE])
        ->and($handle->getFinalState()->get('a_ran'))->toBeTrue()
        ->and($handle->getFinalState()->get('b_ran'))->toBeTrue()
        ->and($handle->getFinalState()->get('c_ran'))->toBeTrue();
});

it('delivers only text_delta events when event type filter is set', function () {
    registerFilterStreamGraph('filter-event-types');

    $handle = AgenticGraph::stream(
        'filter-event-types',
        new State,
        nodes: ['a'],
        events: ['text_delta'],
        inline: true,
    );

    $typesForNodeA = textDeltaEventTypesFromInline($handle->stream()->inline);

    expect($typesForNodeA)->toBe(['text_delta'])
        ->and($handle->getFinalState()->get('a_ran'))->toBeTrue()
        ->and($handle->getFinalState()->get('b_ran'))->toBeTrue();
});

it('honors the node filter after sync streaming resume', function () {
    registerFilterStreamGraph('filter-stream-resume', interruptAtB: true);

    $handle = AgenticGraph::stream(
        'filter-stream-resume',
        new State,
        nodes: ['a', 'c'],
        inline: true,
    );

    expect($handle->status())->toBe(ExecutionStatus::INTERRUPTED);

    $firstPassNodes = textDeltaNodeNamesFromInline($handle->stream()->inline);

    $resumed = $handle->resume(['continue' => true]);
    $secondPassNodes = textDeltaNodeNamesFromInline($resumed->stream()->inline);
    $finalState = $resumed->getFinalState();

    expect($firstPassNodes)->toBe(['a'])
        ->and($secondPassNodes)->toBe(['c'])
        ->and($finalState->get('a_ran'))->toBeTrue()
        ->and($finalState->get('b_ran'))->toBeTrue()
        ->and($finalState->get('c_ran'))->toBeTrue();
});

it('builds stream options with filters from execution options', function () {
    $options = new ExecutionOptions(
        nodeFilter: ['a', 'c'],
        eventTypeFilter: ['text_delta'],
    );

    $streamOptions = StreamOptions::fromExecution(
        $options,
        delivery: 'iterator',
    );

    expect($streamOptions->nodeFilter)->toBe(['a', 'c'])
        ->and($streamOptions->eventTypeFilter)->toBe(['text_delta'])
        ->and($streamOptions->delivery)->toBe('iterator');
});
