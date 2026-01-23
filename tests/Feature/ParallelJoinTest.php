<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class StartNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('started', true);
    }
}

class NodeA extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('a', 1);
    }
}

class NodeB extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('b', 2);
    }
}

class RespondNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('sum', ($state->get('a', 0) + $state->get('b', 0)));
    }
}

class EndNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

it('executes parallel branches and joins to respond then end', function () {
    $graph = GraphBuilder::create('parallel-join')
        ->description('Test parallel fan-out and join')
        ->addNode('start', StartNode::class)
        ->addNode('a', NodeA::class)
        ->addNode('b', NodeB::class)
        ->addNode('respond', RespondNode::class)
        ->addNode('end', EndNode::class)
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
        ->addJoin(['a', 'b'], 'respond')
        ->addEdge('respond', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();

    GraphRegistry::register('parallel-join', $graph);

    $executor = new GraphExecutor;
    $result = $executor->execute($graph, new State(['base' => 0]));

    expect($result->get('started'))->toBeTrue()
        ->and($result->get('a'))->toBe(1)
        ->and($result->get('b'))->toBe(2)
        ->and($result->get('sum'))->toBe(3);
});
