<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;

class CPStart extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('step', 1);
    }
}

class CPMiddle extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('step', 2);
    }
}

it('creates checkpoint after each executed node', function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    $graph = GraphBuilder::create('cp-graph')
        ->addNode('start', CPStart::class)
        ->addNode('middle', CPMiddle::class)
        ->addNode('end', CPMiddle::class)
        ->addEdge('start', 'middle')
        ->addEdge('middle', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    (new GraphExecutor)->execute($graph, new State);
    $list = (new DatabaseStorage)->list('cp-graph');
    expect($list)->toHaveCount(3);
});
