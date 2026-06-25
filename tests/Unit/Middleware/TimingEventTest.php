<?php

use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeExecutionTimed;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Middleware\TimingMiddleware;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class TENode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        usleep(1000);

        return $state;
    }
}

it('emits NodeExecutionTimed event', function () {
    config()->set('agenticgraph.middleware.global', [TimingMiddleware::class]);
    Event::fake();
    $graph = GraphBuilder::create('te')
        ->addNode('start', TENode::class)
        ->addNode('end', TENode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    (new GraphExecutor)->execute($graph, new State);
    Event::assertDispatched(NodeExecutionTimed::class);
});
