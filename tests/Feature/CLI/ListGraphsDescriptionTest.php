<?php

use Illuminate\Support\Facades\Artisan;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;

class ListDescriptionNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

it('prints no brackets for a graph without a description', function () {
    $plain = GraphBuilder::create('list-plain')->addNode('a', ListDescriptionNode::class)->setEntryPoint('a')->addExitPoint('a')->build();
    $described = GraphBuilder::create('list-described')->description('Answers questions')->addNode('a', ListDescriptionNode::class)->setEntryPoint('a')->addExitPoint('a')->build();
    app(GraphRegistry::class)->register('list-plain', $plain);
    app(GraphRegistry::class)->register('list-described', $described);

    Artisan::call('agenticgraph:list');
    $output = Artisan::output();

    expect($output)->toContain('- list-plain Nodes: 1')
        ->and($output)->not->toContain('()')
        ->and($output)->toContain('- list-described (Answers questions) Nodes: 1');
});
