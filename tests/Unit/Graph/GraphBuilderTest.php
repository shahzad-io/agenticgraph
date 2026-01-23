<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\InvalidGraphException;
use Shahzad\AgenticGraph\Nodes\AbstractNode;

class GBStartNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class GBEndNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

it('builds a valid graph', function () {
    $graph = GraphBuilder::create('test')
        ->addNode('start', GBStartNode::class)
        ->addNode('end', GBEndNode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    expect($graph->hasNode('start'))->toBeTrue()
        ->and($graph->hasNode('end'))->toBeTrue();
});

it('throws for missing edge target', function () {
    expect(fn () => GraphBuilder::create('bad')
        ->addNode('start', GBStartNode::class)
        ->addEdge('start', 'unknown')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build()
    )->toThrow(InvalidGraphException::class);
});
