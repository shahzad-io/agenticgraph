<?php

use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class MWNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('node', true);
    }
}

class TestMiddlewareA
{
    public function handle(StateContract $state, NodeInterface $node, Closure $next): StateContract
    {
        return $next($state->set('mw', ['A']));
    }
}

class TestMiddlewareB
{
    public function handle(StateContract $state, NodeInterface $node, Closure $next): StateContract
    {
        $mw = $state->get('mw', []);
        $mw[] = 'B';

        return $next($state->set('mw', $mw));
    }
}

class TestMiddlewareC
{
    public function handle(StateContract $state, NodeInterface $node, Closure $next): StateContract
    {
        $mw = $state->get('mw', []);
        $mw[] = 'C';

        return $next($state->set('mw', $mw));
    }
}

it('applies global middleware in order', function () {
    config()->set('agenticgraph.middleware.global', [TestMiddlewareA::class, TestMiddlewareB::class, TestMiddlewareC::class]);
    $graph = GraphBuilder::create('mw')
        ->addNode('start', MWNode::class)
        ->addNode('end', MWNode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    $result = (new GraphExecutor)->execute($graph, new State);
    expect($result->get('mw'))->toBe(['A', 'B', 'C']);
});
