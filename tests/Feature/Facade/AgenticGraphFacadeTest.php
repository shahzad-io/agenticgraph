<?php

use Shahzad\AgenticGraph\Facades\AgenticGraph as AG;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\State\State;

class FacadeNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('ok', true);
    }
}

it('uses facade to execute a graph', function () {
    $graph = AG::create('facade')
        ->addNode('start', FacadeNode::class)
        ->addNode('end', FacadeNode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    $result = AG::execute($graph, new State());
    expect($result->get('ok'))->toBeTrue();
});