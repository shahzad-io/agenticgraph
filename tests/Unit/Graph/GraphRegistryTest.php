<?php

use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;

it('registers and retrieves graphs', function () {
    $graph = GraphBuilder::create('reg')
        ->addNode('start', GraphRegistryTestNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();
    GraphRegistry::register('reg', $graph);
    expect(GraphRegistry::get('reg'))->not()->toBeNull()->and(GraphRegistry::all())->toHaveKey('reg');
});

class GraphRegistryTestNode extends \Shahzad\AgenticGraph\Nodes\AbstractNode
{
    public function execute(\Shahzad\AgenticGraph\Contracts\StateContract $state): \Shahzad\AgenticGraph\Contracts\StateContract
    {
        return $state;
    }
}