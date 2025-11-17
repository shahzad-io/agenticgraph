<?php

use Illuminate\Support\Facades\Artisan;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;

class ExecNode extends AbstractNode
{
    public function execute(StateContract $state): \Shahzad\AgenticGraph\Contracts\StateContract
    {
        return $state->set('done', true);
    }
}

it('executes graph via CLI', function () {
    $graph = GraphBuilder::create('cli-exec')
        ->addNode('start', ExecNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();
    GraphRegistry::register('cli-exec', $graph);
    $code = Artisan::call('agenticgraph:execute', ['name' => 'cli-exec', '--state' => json_encode(['foo' => 'bar'])]);
    expect($code)->toBe(0);
});