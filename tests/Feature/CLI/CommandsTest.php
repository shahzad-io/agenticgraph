<?php

use Illuminate\Support\Facades\Artisan;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;

class CLINode extends AbstractNode
{
    public function execute(StateContract $state): \Shahzad\AgenticGraph\Contracts\StateContract
    {
        return $state;
    }
}

it('lists graphs', function () {
    $graph = GraphBuilder::create('cli-graph')
        ->addNode('start', CLINode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('cli-graph', $graph);
    $code = Artisan::call('agenticgraph:list');
    expect($code)->toBe(0);
});

it('visualizes graph', function () {
    $graph = GraphBuilder::create('viz-graph')
        ->addNode('start', CLINode::class)
        ->addNode('end', CLINode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('viz-graph', $graph);
    $code = Artisan::call('agenticgraph:visualize', ['name' => 'viz-graph']);
    expect($code)->toBe(0);
});
