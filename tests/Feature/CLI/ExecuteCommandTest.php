<?php

use Illuminate\Support\Facades\Artisan;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;

class ExecNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
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
    app(GraphRegistry::class)->register('cli-exec', $graph);
    $code = Artisan::call('agenticgraph:execute', ['name' => 'cli-exec', '--state' => json_encode(['foo' => 'bar'])]);
    expect($code)->toBe(0);
});
