<?php

use Illuminate\Support\Facades\Artisan;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;

class VizNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

it('writes mermaid output to file', function () {
    $graph = GraphBuilder::create('viz-file')
        ->addNode('start', VizNode::class)
        ->addNode('end', VizNode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    app(GraphRegistry::class)->register('viz-file', $graph);
    $path = sys_get_temp_dir().'/agenticgraph-viz.mmd';
    @unlink($path);
    $code = Artisan::call('agenticgraph:visualize', ['name' => 'viz-file', '--output' => $path]);
    expect($code)->toBe(0);
    $content = file_get_contents($path);
    expect($content)->toContain('graph TD')->and($content)->toContain('start --> end');
});
