<?php

use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\RouteFellBack;
use Shahzad\AgenticGraph\Exceptions\RoutingException;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\RoutingEngine;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Routers\AbstractRouter;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;

class DefaultRouteStep extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('visited', [...$state->get('visited', []), $this->getName()]);
    }
}

class DefaultRouteStreamedStep extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('visited', [...$state->get('visited', []), $this->getName()]);
    }
}

/** Returns whatever it is given, with the default route it is given. */
class FixedReplyRouter extends AbstractRouter
{
    /**
     * @param  string|array<int, string>  $reply
     */
    public function __construct(private readonly string|array $reply, ?string $defaultRoute = null)
    {
        $this->routes = ['a' => 'a', 'b' => 'b'];
        $this->defaultRoute = $defaultRoute;
    }

    public function route(StateContract $state): string|array
    {
        return $this->reply;
    }
}

/**
 * @param  string|array<int, string>  $reply
 */
function defaultRouteGraph(string|array $reply, ?string $defaultRoute, string $node = DefaultRouteStep::class, string $name = 'default-route'): Graph
{
    return GraphBuilder::create($name)
        ->addNode('start', $node)
        ->addNode('a', $node)
        ->addNode('b', $node)
        ->addConditionalEdge('start', new FixedReplyRouter($reply, $defaultRoute))
        ->setEntryPoint('start')
        ->addExitPoint('a')
        ->addExitPoint('b')
        ->build();
}

it('takes the default route when a router returns an empty or unknown route', function (string|array $reply, string $reason) {
    Event::fake([RouteFellBack::class]);

    $next = (new RoutingEngine(app()))->determineNext(defaultRouteGraph($reply, 'b'), 'start', new State);

    expect($next)->toBe('b');
    Event::assertDispatched(RouteFellBack::class, fn (RouteFellBack $e): bool => $e->router === FixedReplyRouter::class && $e->route === 'b' && $e->reason === $reason && $e->error === null);
})->with([
    'empty string' => ['', 'empty_route'],
    'empty array' => [[], 'empty_route'],
    'unknown node' => ['ghost', 'unknown_route'],
]);

it('leaves a valid route and a valid fan-out alone', function (string|array $reply) {
    Event::fake([RouteFellBack::class]);

    expect((new RoutingEngine(app()))->determineNext(defaultRouteGraph($reply, 'b'), 'start', new State))->toBe($reply);
    Event::assertNotDispatched(RouteFellBack::class);
})->with([
    'route' => ['a'],
    'fan-out' => [['a', 'b']],
]);

it('throws RoutingException without a usable default route', function (string|array $reply, ?string $defaultRoute, string $reason) {
    expect(fn () => (new RoutingEngine(app()))->determineNext(defaultRouteGraph($reply, $defaultRoute), 'start', new State))
        ->toThrow(fn (RoutingException $e) => expect([$e->router, $e->reason])->toBe([FixedReplyRouter::class, $reason]));
})->with([
    'no default' => ['ghost', null, 'unknown_route'],
    'empty, no default' => ['', null, 'empty_route'],
    'default not in the graph' => ['ghost', 'nowhere', 'unknown_route'],
    'fan-out with an unknown node' => [['a', 'ghost'], 'b', 'unknown_route'],
]);

it('applies the default route in a sync run', function () {
    Event::fake([RouteFellBack::class]);

    $state = app(GraphExecutor::class)->execute(defaultRouteGraph('ghost', 'b'), new State);

    expect($state->get('visited'))->toBe(['Default Route Step', 'Default Route Step']);
    Event::assertDispatchedTimes(RouteFellBack::class, 1);
});

it('applies the default route in a streamed run', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    AgenticGraph::register('default-route-streamed', defaultRouteGraph('ghost', 'b', DefaultRouteStreamedStep::class, 'default-route-streamed'));

    $handle = AgenticGraph::execute('default-route-streamed', new State, new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR));
    $types = array_map(fn ($event) => $event->type(), iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]), false));

    expect($types)->toContain(StreamLifecycleEventTypes::GRAPH_COMPLETE)
        ->and(array_count_values($types)[StreamLifecycleEventTypes::NODE_COMPLETE] ?? 0)->toBe(2);
});

it('no longer declares getTimeout() on nodes', function () {
    expect(method_exists(NodeInterface::class, 'getTimeout'))->toBeFalse()
        ->and(method_exists(AbstractNode::class, 'getTimeout'))->toBeFalse();
});
