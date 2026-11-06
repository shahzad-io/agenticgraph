<?php

use Illuminate\Support\Facades\Artisan;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\Graph;
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

class ExecConfigGraph
{
    public static function build(): Graph
    {
        return GraphBuilder::create('cli-config')
            ->addNode('start', ExecNode::class)
            ->setEntryPoint('start')
            ->addExitPoint('start')
            ->build();
    }
}

it('executes a graph registered in config, not in the registry', function () {
    app(GraphRegistry::class)->clear();
    config()->set('agenticgraph.graphs.cli-config', ExecConfigGraph::class);

    $this->artisan('agenticgraph:execute', ['name' => 'cli-config'])
        ->expectsOutputToContain('"done": true')
        ->assertSuccessful();
});

it('finds a config graph from a fresh process through GraphRegistry::get()', function () {
    app(GraphRegistry::class)->clear();
    config()->set('agenticgraph.graphs.cli-config', ExecConfigGraph::class);

    expect(app(GraphRegistry::class)->get('cli-config'))->toBeInstanceOf(Graph::class)
        ->and(app(GraphRegistry::class)->get('cli-missing'))->toBeNull();
});
