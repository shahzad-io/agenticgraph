<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\DB;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;

class TerminalLastOkNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        if ($this->isStreaming()) {
            $this->streamEvent('text_delta', ['delta' => 'hi']);
        }

        return $state->set('done', true);
    }
}

class TerminalLastFailingNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException('node failed');
    }
}

class TerminalLastInterruptNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('visited', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return true;
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Needs approval';
    }
}

class TerminalLastThrowingRouter implements RouterInterface
{
    public function route(StateContract $state): string|array
    {
        throw new RuntimeException('routing failed');
    }

    public function getAvailableRoutes(): array
    {
        return ['end'];
    }

    public function getDefaultRoute(): ?string
    {
        return null;
    }

    public function validateRoute(string $route): bool
    {
        return true;
    }
}

/**
 * Records every broadcast; optionally fails one event type, as a broken transport would.
 */
class TerminalLastBroadcaster implements Broadcaster
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(private readonly ?string $failOn = null) {}

    public function auth($request)
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return null;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        if ($event === $this->failOn) {
            throw new RuntimeException('transport failed on '.$event);
        }
        $this->events[] = $event;
    }
}

/**
 * Runs a sync broadcast (store enabled) and returns stored event types and broadcast event names.
 *
 * @return array{stored: list<string>, broadcast: list<string>, error: ?string}
 */
function terminalLastRun(string $name, GraphBuilder $builder, ?string $failOn = null): array
{
    $broadcaster = new TerminalLastBroadcaster($failOn);
    app()->instance(Broadcaster::class, $broadcaster);
    AgenticGraph::register($name, $builder->build());

    $error = null;
    try {
        AgenticGraph::execute($name, new State(['topic' => 'x']), new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::BROADCAST,
            interrupts: true,
        ));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $streamId = DB::table('agenticgraph_executions')->where('graph_name', $name)->value('stream_id');
    $stored = $streamId === null ? [] : array_map(
        fn (StreamEvent $event) => $event->type(),
        array_values(iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, 1, 1000), false))
    );

    return ['stored' => $stored, 'broadcast' => $broadcaster->events, 'error' => $error];
}

function terminalLastSingle(string $name, string $node): GraphBuilder
{
    return GraphBuilder::create($name)->addNode('a', $node)->setEntryPoint('a')->addExitPoint('a');
}

/**
 * The F12 rule for one run: one terminal, last stored; one stream_close, last broadcast.
 */
function expectTerminalLast(array $run): void
{
    expect($run['stored'])->not->toBeEmpty()
        ->and(end($run['stored']))->toBe('execution_terminal')
        ->and(array_count_values($run['stored'])['execution_terminal'])->toBe(1)
        ->and(end($run['broadcast']))->toBe('stream_close')
        ->and(array_count_values($run['broadcast'])['stream_close'])->toBe(1);
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.checkpoint.enabled', true);
    app()->forgetInstance(StreamStoreInterface::class);
});

it('ends a successful run with execution_terminal and one stream_close', function () {
    $run = terminalLastRun('f12-success', terminalLastSingle('f12-success', TerminalLastOkNode::class));

    expectTerminalLast($run);
    expect(array_slice($run['stored'], -2))->toBe(['graph_complete', 'execution_terminal']);
});

it('emits graph_failed before the single terminal on a node error', function () {
    $run = terminalLastRun('f12-node-error', terminalLastSingle('f12-node-error', TerminalLastFailingNode::class));

    expectTerminalLast($run);
    expect($run['error'])->toBe('node failed')
        ->and(array_slice($run['stored'], -3))->toBe(['node_error', 'graph_failed', 'execution_terminal'])
        ->and(array_slice($run['broadcast'], -3))->toBe(['node_error', 'graph_failed', 'stream_close']);
});

it('ends with the terminal when the graph fails outside a node', function () {
    $builder = GraphBuilder::create('f12-graph-failure')
        ->addNode('a', TerminalLastOkNode::class)
        ->addNode('end', TerminalLastOkNode::class)
        ->addConditionalEdge('a', new TerminalLastThrowingRouter)
        ->setEntryPoint('a')
        ->addExitPoint('end');

    $run = terminalLastRun('f12-graph-failure', $builder);

    expectTerminalLast($run);
    expect($run['error'])->toBe('routing failed')
        ->and(array_slice($run['stored'], -2))->toBe(['graph_failed', 'execution_terminal']);
});

it('ends with the terminal when setup fails on graph_start delivery', function () {
    $run = terminalLastRun('f12-setup', terminalLastSingle('f12-setup', TerminalLastOkNode::class), failOn: 'graph_start');

    expect($run['error'])->toBe('transport failed on graph_start')
        ->and(end($run['stored']))->toBe('execution_terminal')
        ->and(array_count_values($run['stored'])['execution_terminal'])->toBe(1)
        ->and(array_slice($run['stored'], -2))->toBe(['graph_failed', 'execution_terminal'])
        ->and(end($run['broadcast']))->toBe('stream_close')
        ->and(array_count_values($run['broadcast'])['stream_close'])->toBe(1);
});

it('ends an interrupted run with the terminal and one stream_close', function () {
    $run = terminalLastRun('f12-interrupt', terminalLastSingle('f12-interrupt', TerminalLastInterruptNode::class));

    expectTerminalLast($run);
    expect(array_slice($run['stored'], -2))->toBe(['node_interrupt', 'execution_terminal']);
});

it('closes once, after graph_failed, when the transport fails mid-node', function () {
    $run = terminalLastRun('f12-transport', terminalLastSingle('f12-transport', TerminalLastOkNode::class), failOn: 'text_delta');

    expectTerminalLast($run);
    expect($run['error'])->toBe('transport failed on text_delta')
        ->and(array_slice($run['stored'], -2))->toBe(['graph_failed', 'execution_terminal'])
        ->and(array_slice($run['broadcast'], -2))->toBe(['graph_failed', 'stream_close']);
});
