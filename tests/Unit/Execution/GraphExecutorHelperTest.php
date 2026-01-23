<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

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
    $executor = new GraphExecutor;
    $state = new State;
    $next = (new ReflectionClass($executor))->getMethod('determinePostParallelNext')->invoke($executor, $graph, ['a', 'b'], $state);
    expect($next)->toBe('c');
});
