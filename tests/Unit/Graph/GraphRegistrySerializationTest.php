<?php

use Illuminate\Support\Facades\Cache;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

/**
 * Regression tests for GraphRegistry P0: built graphs must never pass through a
 * serializing Laravel cache. These tests intentionally use serialize=true on
 * the array cache driver, the opposite of TestCase's serialize=false default.
 */
beforeEach(function () {
    config([
        'cache.default' => 'array',
        'cache.stores.array' => [
            'driver' => 'array',
            'serialize' => true,
        ],
    ]);
    Cache::flush();
    app(GraphRegistry::class)->clear();
});

afterEach(function () {
    Cache::flush();
    app(GraphRegistry::class)->clear();
});

it('does not write graphs to Laravel cache on register', function () {
    $graph = buildGraphRegistrySerializationGraph('no-cache-write');

    app(GraphRegistry::class)->register('no-cache-write', $graph);

    expect(Cache::has('agenticgraph:graphs:no-cache-write'))->toBeFalse()
        ->and(Cache::has('agenticgraph:graphs:index'))->toBeFalse()
        ->and(Cache::get('agenticgraph:graphs:no-cache-write'))->toBeNull();
});

it('returns a healthy graph with a live router instance across repeated getOrBuild calls', function () {
    config([
        'agenticgraph.graphs' => [
            'config-discovery' => GraphRegistryConfigProvider::class,
        ],
    ]);

    $registry = app(GraphRegistry::class);

    $first = $registry->getOrBuild('config-discovery');
    $second = $registry->getOrBuild('config-discovery');

    expect($first)->toBeInstanceOf(Graph::class)
        ->and($second)->toBeInstanceOf(Graph::class)
        ->and($second->getNode('start'))->toBeInstanceOf(GraphRegistryLiveDependencyNode::class)
        ->and($second->getNode('start')->execute(new State))->toBeInstanceOf(StateContract::class);

    $result = (new GraphExecutor)->execute($second, new State(['probe' => true]));
    expect($result->get('probe'))->toBeTrue();
});

it('returns a healthy graph from get after register with non-serializable dependencies', function () {
    $graph = buildGraphRegistrySerializationGraph('live-router');

    $registry = app(GraphRegistry::class);
    $registry->register('live-router', $graph);

    $retrieved = $registry->get('live-router');

    expect($retrieved)->toBeInstanceOf(Graph::class)
        ->and($retrieved->getNode('start'))->toBeInstanceOf(GraphRegistryLiveDependencyNode::class);

    $routes = $retrieved->getEdgesFrom('start')[0]->getCondition();
    expect($routes)->toBeInstanceOf(RouterInterface::class)
        ->and($routes->getAvailableRoutes())->toBe(['end']);
});

it('does not read graphs from Laravel cache on get', function () {
    $graph = buildGraphRegistrySerializationGraph('stale-cache');

    Cache::put('agenticgraph:graphs:stale-cache', $graph, 3600);
    Cache::put('agenticgraph:graphs:index', ['stale-cache'], 3600);

    app()->forgetInstance(GraphRegistry::class);

    expect(app(GraphRegistry::class)->get('stale-cache'))->toBeNull();
});

it('resolves a healthy graph for the batch-completion registry lookup site', function () {
    $graph = buildGraphRegistrySerializationGraph('p14-registry-get');
    app(GraphRegistry::class)->register('p14-registry-get', $graph);

    $resolved = app(GraphRegistry::class)->get('p14-registry-get');

    expect($resolved)->toBeInstanceOf(Graph::class)
        ->and($resolved->hasNode('start'))->toBeTrue()
        ->and($resolved->hasNode('end'))->toBeTrue()
        ->and($resolved->getEdgesFrom('start')[0]->getCondition())->toBeInstanceOf(RouterInterface::class);
});

it('ignores poisoned legacy cache entries and rebuilds from config', function () {
    $poisoned = buildGraphRegistrySerializationGraph('legacy-poison');
    Cache::put('agenticgraph:graphs:legacy-poison', $poisoned, 3600);
    Cache::put('agenticgraph:graphs:index', ['legacy-poison'], 3600);

    config([
        'agenticgraph.graphs' => [
            'legacy-poison' => GraphRegistryConfigProvider::class,
        ],
    ]);

    $registry = app(GraphRegistry::class);
    $graph = $registry->getOrBuild('legacy-poison');

    expect($graph)->toBeInstanceOf(Graph::class)
        ->and($graph->getNode('start'))->toBeInstanceOf(GraphRegistryLiveDependencyNode::class);
});

function buildGraphRegistrySerializationGraph(string $name): Graph
{
    return GraphBuilder::create($name)
        ->addNode('start', GraphRegistryLiveDependencyNode::class)
        ->addNode('end', GraphRegistrySerializationEndNode::class)
        ->addConditionalEdge('start', new GraphRegistryLiveRouter)
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
}

class GraphRegistryConfigProvider
{
    public static function build(): Graph
    {
        return buildGraphRegistrySerializationGraph('config-discovery');
    }
}

class GraphRegistryLiveDependencyNode extends AbstractNode
{
    private stdClass $nonSerializableDependency;

    public function __construct()
    {
        $this->nonSerializableDependency = (object) ['handle' => fopen('php://memory', 'r+')];
    }

    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class GraphRegistrySerializationEndNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class GraphRegistryLiveRouter implements RouterInterface
{
    public function route(StateContract $state): string|array
    {
        return 'end';
    }

    public function getAvailableRoutes(): array
    {
        return ['end'];
    }

    public function getDefaultRoute(): ?string
    {
        return 'end';
    }

    public function validateRoute(string $route): bool
    {
        return $route === 'end';
    }
}
