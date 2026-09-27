<?php

use Illuminate\Support\Facades\Log;
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

it('does not warn that join sources are dead ends', function () {
    Log::spy();

    GraphBuilder::create('join-graph')
        ->addNode('start', GBStartNode::class)
        ->addNode('branch_a', GBStartNode::class)
        ->addNode('branch_b', GBStartNode::class)
        ->addNode('dangling', GBStartNode::class)
        ->addNode('end', GBEndNode::class)
        ->addEdge('start', 'branch_a')
        ->addEdge('start', 'branch_b')
        ->addEdge('start', 'dangling')
        ->addJoin(['branch_a', 'branch_b'], 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();

    Log::shouldNotHaveReceived('warning', ["Node 'branch_a' has no outgoing edges and is not an exit point"]);
    Log::shouldNotHaveReceived('warning', ["Node 'branch_b' has no outgoing edges and is not an exit point"]);
    Log::shouldHaveReceived('warning')->with("Node 'dangling' has no outgoing edges and is not an exit point")->once();
});
