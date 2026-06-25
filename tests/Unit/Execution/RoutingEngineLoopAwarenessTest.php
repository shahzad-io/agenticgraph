<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Shahzad\AgenticGraph\Contracts\LoopAwareRouterInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\LoopContext;
use Shahzad\AgenticGraph\Execution\RoutingEngine;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\State\State;

class PostParallelNodeA extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class PostParallelNodeB extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class PostParallelNodeNext extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class RecordingLoopAwareRouter implements LoopAwareRouterInterface
{
    public bool $routeWithContextCalled = false;

    public bool $plainRouteCalled = false;

    public function routeWithContext(StateContract $state, ExecutionContext $context): string|array
    {
        $this->routeWithContextCalled = true;

        return 'next';
    }

    public function route(StateContract $state): string|array
    {
        $this->plainRouteCalled = true;

        return 'next';
    }

    public function getAvailableRoutes(): array
    {
        return ['next'];
    }

    public function getDefaultRoute(): ?string
    {
        return 'next';
    }

    public function validateRoute(string $route): bool
    {
        return $route === 'next';
    }
}

it('uses resolveRoute for loop-aware routers in determineNext', function () {
    $router = new RecordingLoopAwareRouter;

    $graph = GraphBuilder::create('resolve-route-next')
        ->addNode('start', PostParallelNodeA::class)
        ->addNode('next', PostParallelNodeNext::class)
        ->addConditionalEdge('start', $router)
        ->addEdge('start', 'next')
        ->setEntryPoint('start')
        ->addExitPoint('next')
        ->build();

    $container = app();
    $container->instance(ExecutionContext::class, new ExecutionContext(
        executionId: 'resolve-route-exec',
        startTime: microtime(true),
    ));

    $routingEngine = new RoutingEngine($container);
    $next = $routingEngine->determineNext($graph, 'start', new State);

    expect($next)->toBe('next')
        ->and($router->routeWithContextCalled)->toBeTrue()
        ->and($router->plainRouteCalled)->toBeFalse();

    $container->forgetInstance(ExecutionContext::class);
});

it('passes loop context to loop-aware routers after a parallel join', function () {
    $router = new RecordingLoopAwareRouter;

    $graph = GraphBuilder::create('post-parallel-loop-aware')
        ->addNode('start', PostParallelNodeA::class)
        ->addNode('a', PostParallelNodeA::class)
        ->addNode('b', PostParallelNodeB::class)
        ->addNode('next', PostParallelNodeNext::class)
        ->addConditionalEdge('start', new class implements RouterInterface
        {
            public function route(StateContract $state): string|array
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
        ->addConditionalEdge('a', $router)
        ->addEdge('a', 'next')
        ->addConditionalEdge('b', $router)
        ->addEdge('b', 'next')
        ->setEntryPoint('start')
        ->addExitPoint('next')
        ->build();

    $container = app();
    $container->instance(ExecutionContext::class, new ExecutionContext(
        executionId: 'post-parallel-exec',
        startTime: microtime(true),
        nodeVisitCounts: ['a' => 1, 'b' => 1],
    ));

    $routingEngine = new RoutingEngine($container);
    $next = $routingEngine->determinePostParallelNext($graph, ['a', 'b'], new State);

    expect($next)->toBe('next')
        ->and($router->routeWithContextCalled)->toBeTrue()
        ->and($router->plainRouteCalled)->toBeFalse();

    $container->forgetInstance(ExecutionContext::class);
});

it('routes loop-aware PrismRouter through routeWithContext in resolveRoute', function () {
    $router = new class(['next' => 'next path'], defaultRoute: 'next') extends PrismRouter
    {
        public bool $routeWithContextReached = false;

        protected function buildPromptWithLoop(StateContract $state, ?LoopContext $loop = null): string
        {
            if ($loop !== null) {
                $this->routeWithContextReached = true;
            }

            return 'minimal-prompt';
        }
    };

    $container = app();
    $container->instance(ExecutionContext::class, new ExecutionContext(
        executionId: 'prism-resolve-route',
        startTime: microtime(true),
    ));

    if (! class_exists(Prism::class)) {
        expect($router)->toBeInstanceOf(LoopAwareRouterInterface::class);
        $container->forgetInstance(ExecutionContext::class);

        return;
    }

    config()->set('agenticgraph.prism.cache_enabled', false);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['route' => 'next'])
            ->withUsage(new Usage(1, 1))
            ->withMeta(new Meta('fake-resolve', 'fake-model')),
    ]);

    $routingEngine = new RoutingEngine($container);
    $route = $routingEngine->resolveRoute($router, new State);

    expect($route)->toBe('next')
        ->and($router->routeWithContextReached)->toBeTrue();

    $container->forgetInstance(ExecutionContext::class);
});
