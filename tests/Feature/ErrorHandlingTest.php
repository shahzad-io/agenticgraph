<?php

use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\State\State;

class FlakyNode extends AbstractNode
{
    private static int $attempt = 0;

    public function execute(StateContract $state): StateContract
    {
        self::$attempt++;
        if (self::$attempt < 3) {
            throw new RuntimeException('Temporary failure');
        }
        return $state->set('attempt', self::$attempt);
    }
}

class EHEndNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

it('retries flaky node until success', function () {
    $graph = GraphBuilder::create('retry')
        ->addNode('flaky', FlakyNode::class)
        ->addNode('end', EHEndNode::class)
        ->addEdge('flaky', 'end')
        ->setEntryPoint('flaky')
        ->addExitPoint('end')
        ->build();
    $result = (new GraphExecutor())->execute($graph, new State());
    expect($result->get('attempt'))->toBe(3);
});