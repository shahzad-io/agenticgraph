<?php

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\LoopAwareRouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\LoopContext;
use Shahzad\AgenticGraph\Execution\RoutingEngine;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\State\State;

class LoopAwarenessNodeA extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class LoopAwarenessNodeB extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class LoopAwarenessNodeNext extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class RecordingBuildPromptRouter extends PrismRouter
{
    public ?LoopContext $receivedLoop = null;

    public string $capturedPrompt = '';

    protected function buildPromptWithLoop(StateContract $state, ?LoopContext $loop = null): string
    {
        $this->receivedLoop = $loop;
        $this->capturedPrompt = parent::buildPromptWithLoop($state, $loop);

        return $this->capturedPrompt;
    }
}

class LegacySingleParamBuildPromptRouter extends PrismRouter
{
    protected function buildPrompt(StateContract $state): string
    {
        return 'legacy-single-param-prompt';
    }
}

class TracingAfterRouteRouter extends PrismRouter
{
    /** @var array<int, array<string, mixed>> */
    public array $afterRouteCalls = [];

    protected function buildPrompt(StateContract $state): string
    {
        return 'tracing-prompt';
    }

    protected function afterRoute(StateContract $state, string|array $route, ?LoopContext $loop, bool $fromCache): void
    {
        $this->afterRouteCalls[] = [
            'route' => $route,
            'loop' => $loop,
            'fromCache' => $fromCache,
        ];
    }
}

class NoLoopContextAppendRouter extends PrismRouter
{
    protected bool $includeLoopContextInPrompt = false;
}

function bindLoopAwarenessContext(Container $container, ExecutionContext $context): void
{
    $container->instance(ExecutionContext::class, $context);
}

function bindLoopAwarenessGraph(Container $container, Graph $graph): void
{
    $container->instance(Graph::class, $graph);
}

function makeLoopAwarenessContext(array $nodeVisitCounts = []): ExecutionContext
{
    return new ExecutionContext(
        executionId: 'loop-test-exec',
        startTime: microtime(true),
        globalIterations: array_sum($nodeVisitCounts),
        nodeVisitCounts: $nodeVisitCounts,
    );
}

it('implements LoopAwareRouterInterface', function () {
    $router = new PrismRouter(['a' => 'a path'], defaultRoute: 'a');

    expect($router)->toBeInstanceOf(LoopAwareRouterInterface::class);
});

it('passes a non-null LoopContext to buildPrompt via routeWithContext', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['route' => 'writer'])
            ->withUsage(new Usage(1, 1))
            ->withMeta(new Meta('fake-loop', 'fake-model')),
    ]);

    $router = new RecordingBuildPromptRouter([
        'writer' => 'writer path',
        'critic' => 'critic path',
    ], defaultRoute: 'writer');

    $context = makeLoopAwarenessContext(['writer' => 2, 'critic' => 1]);

    $router->routeWithContext(new State, $context);

    expect($router->receivedLoop)->toBeInstanceOf(LoopContext::class)
        ->and($router->receivedLoop->visitCount('writer'))->toBe(2);
});

it('passes a non-null LoopContext to buildPrompt via route when context is container-bound', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['route' => 'writer'])
            ->withUsage(new Usage(1, 1))
            ->withMeta(new Meta('fake-loop-route', 'fake-model')),
    ]);

    $container = app();
    bindLoopAwarenessContext($container, makeLoopAwarenessContext(['writer' => 1]));

    $router = new RecordingBuildPromptRouter([
        'writer' => 'writer path',
        'critic' => 'critic path',
    ], defaultRoute: 'writer', container: $container);

    $router->route(new State);

    expect($router->receivedLoop)->toBeInstanceOf(LoopContext::class);
});

it('keeps single-parameter buildPrompt overrides working', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['route' => 'a'])
            ->withUsage(new Usage(1, 1))
            ->withMeta(new Meta('fake-legacy', 'fake-model')),
    ]);

    $router = new LegacySingleParamBuildPromptRouter(['a' => 'a path'], defaultRoute: 'a');

    expect($router->route(new State))->toBe('a');
});

it('fires afterRoute with route loop and fromCache flag', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', true);
    config()->set('agenticgraph.prism.cache_ttl', 60);

    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('has')->once()->andReturn(false);
    $cache->shouldReceive('put')->once()->andReturn(true);
    $cache->shouldReceive('has')->once()->andReturn(true);
    $cache->shouldReceive('get')->once()->andReturn('writer');

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['route' => 'writer'])
            ->withUsage(new Usage(1, 1))
            ->withMeta(new Meta('fake-after-route', 'fake-model')),
    ]);

    $container = app();
    bindLoopAwarenessContext($container, makeLoopAwarenessContext(['writer' => 1]));

    $router = new TracingAfterRouteRouter([
        'writer' => 'writer path',
        'critic' => 'critic path',
    ], defaultRoute: 'writer', cache: $cache, container: $container);

    $state = new State;

    $router->route($state);
    $router->route($state);

    expect($router->afterRouteCalls)->toHaveCount(2)
        ->and($router->afterRouteCalls[0]['route'])->toBe('writer')
        ->and($router->afterRouteCalls[0]['loop'])->toBeInstanceOf(LoopContext::class)
        ->and($router->afterRouteCalls[0]['fromCache'])->toBeFalse()
        ->and($router->afterRouteCalls[1]['fromCache'])->toBeTrue();
});

it('appends loop context to default buildPrompt only on cyclic graphs', function () {
    $cyclicGraph = GraphBuilder::create('cyclic-loop-prompt')
        ->addNode('writer', LoopAwarenessNodeA::class)
        ->addNode('critic', LoopAwarenessNodeB::class)
        ->addEdge('writer', 'critic')
        ->addEdge('critic', 'writer')
        ->setEntryPoint('writer')
        ->addExitPoint('writer')
        ->build();

    $acyclicGraph = GraphBuilder::create('acyclic-loop-prompt')
        ->addNode('start', LoopAwarenessNodeA::class)
        ->addNode('end', LoopAwarenessNodeB::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();

    $container = app();
    bindLoopAwarenessContext($container, makeLoopAwarenessContext(['writer' => 3, 'critic' => 3, 'fact_checker' => 0]));

    $router = new RecordingBuildPromptRouter([
        'writer' => 'writer path',
        'critic' => 'critic path',
        'fact_checker' => 'fact checker path',
    ], defaultRoute: 'writer', container: $container);

    bindLoopAwarenessGraph($container, $cyclicGraph);
    $method = new ReflectionMethod($router, 'buildPromptWithLoop');
    $cyclicPrompt = $method->invoke($router, new State, LoopContext::from($container->make(ExecutionContext::class)));

    bindLoopAwarenessGraph($container, $acyclicGraph);
    $acyclicPrompt = $method->invoke($router, new State, LoopContext::from($container->make(ExecutionContext::class)));

    expect($cyclicPrompt)->toContain('Loop context:')
        ->and($cyclicPrompt)->toContain('Global iterations:')
        ->and($cyclicPrompt)->toContain('fact_checker:0')
        ->and($cyclicPrompt)->toContain('Unvisited route targets: fact_checker')
        ->and($acyclicPrompt)->not->toContain('Loop context:');
});

it('suppresses auto loop context when includeLoopContextInPrompt is false', function () {
    $cyclicGraph = GraphBuilder::create('cyclic-opt-out')
        ->addNode('writer', LoopAwarenessNodeA::class)
        ->addNode('critic', LoopAwarenessNodeB::class)
        ->addEdge('writer', 'critic')
        ->addEdge('critic', 'writer')
        ->setEntryPoint('writer')
        ->addExitPoint('writer')
        ->build();

    $container = app();
    bindLoopAwarenessContext($container, makeLoopAwarenessContext(['writer' => 1]));
    bindLoopAwarenessGraph($container, $cyclicGraph);

    $router = new NoLoopContextAppendRouter([
        'writer' => 'writer path',
        'critic' => 'critic path',
    ], defaultRoute: 'writer', container: $container);

    $method = new ReflectionMethod($router, 'buildPromptWithLoop');
    $prompt = $method->invoke($router, new State, LoopContext::from($container->make(ExecutionContext::class)));

    expect($prompt)->not->toContain('Loop context:');
});

it('routes PrismRouter through routeWithContext in the routing engine', function () {
    if (! class_exists(Prism::class)) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['route' => 'next'])
            ->withUsage(new Usage(1, 1))
            ->withMeta(new Meta('fake-engine', 'fake-model')),
    ]);

    $router = new RecordingBuildPromptRouter([
        'next' => 'next path',
        'other' => 'other path',
    ], defaultRoute: 'next');

    $graph = GraphBuilder::create('engine-prism-loop')
        ->addNode('start', LoopAwarenessNodeA::class)
        ->addNode('next', LoopAwarenessNodeNext::class)
        ->addConditionalEdge('start', $router)
        ->addEdge('start', 'next')
        ->setEntryPoint('start')
        ->addExitPoint('next')
        ->build();

    $container = app();
    bindLoopAwarenessContext($container, makeLoopAwarenessContext(['start' => 1]));

    $routingEngine = new RoutingEngine($container);
    $routingEngine->determineNext($graph, 'start', new State);

    expect($router->receivedLoop)->toBeInstanceOf(LoopContext::class);
});

afterEach(function () {
    if (app()->bound(ExecutionContext::class)) {
        app()->forgetInstance(ExecutionContext::class);
    }
    if (app()->bound(Graph::class)) {
        app()->forgetInstance(Graph::class);
    }
    Mockery::close();
});
