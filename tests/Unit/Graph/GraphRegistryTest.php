<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;

it('registers and retrieves graphs', function () {
    $registry = app(GraphRegistry::class);

    $graph = GraphBuilder::create('reg')
        ->addNode('start', GraphRegistryTestNode::class)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    $registry->register('reg', $graph);

    expect($registry->get('reg'))->not()->toBeNull()
        ->and($registry->all())->toHaveKey('reg')
        ->and($registry->has('reg'))->toBeTrue()
        ->and($registry->count())->toBeGreaterThan(0);
});

class GraphRegistryTestNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}
