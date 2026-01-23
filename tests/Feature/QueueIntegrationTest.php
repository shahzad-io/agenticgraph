<?php

use Illuminate\Support\Facades\Bus;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class SimpleNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

it('dispatches async job', function () {
    Bus::fake();
    $graph = GraphBuilder::create('q')
        ->addNode('start', SimpleNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();
    $executor = new GraphExecutor;
    $executor->executeAsync($graph, new State);
    Bus::assertDispatched(ExecuteGraphJob::class);
});
