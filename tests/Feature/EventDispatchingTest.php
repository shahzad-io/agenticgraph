<?php

use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class LinearNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('x', 1);
    }
}

it('dispatches graph and node events', function () {
    Event::fake();
    $graph = GraphBuilder::create('linear')
        ->addNode('start', LinearNode::class)
        ->addNode('end', LinearNode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    $executor = new GraphExecutor;
    $executor->execute($graph, new State);
    Event::assertDispatched(GraphExecutionStarted::class);
    Event::assertDispatched(NodeExecutionStarted::class);
    Event::assertDispatched(NodeExecutionCompleted::class);
    Event::assertDispatched(GraphExecutionCompleted::class);
});
