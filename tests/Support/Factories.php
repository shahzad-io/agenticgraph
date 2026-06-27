<?php

use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class DummyNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

function createSimpleGraph(string $name = 'test'): Graph
{
    return GraphBuilder::create($name)
        ->addNode('start', DummyNode::class)
        ->addNode('end', DummyNode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
}

function createLargeGraph(int $nodes = 10): Graph
{
    $builder = GraphBuilder::create('large');
    for ($i = 0; $i < $nodes; $i++) {
        $builder->addNode('node'.$i, DummyNode::class);
        if ($i > 0) {
            $builder->addEdge('node'.($i - 1), 'node'.$i);
        }
    }

    return $builder->setEntryPoint('node0')->addExitPoint('node'.($nodes - 1))->build();
}

function createTestState(array $data = []): State
{
    return new State(array_merge(['test' => true], $data));
}

function createCheckpoint(string $graph = 'g', string $exec = 'e'): Checkpoint
{
    $c = new Checkpoint;
    $c->id = (string) Str::uuid();
    $c->graphName = $graph;
    $c->executionId = $exec;
    $c->state = createTestState();
    $c->currentNode = 'node1';
    $c->metadata = [];
    $c->createdAt = new DateTimeImmutable;

    return $c;
}
