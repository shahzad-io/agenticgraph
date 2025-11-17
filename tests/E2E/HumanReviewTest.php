<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;

class HRStartNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class HRRespondNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('needs_review', true);
    }
}

class HRHumanReviewNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('completed', true);
    }
}

class HREndNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('completed', true);
    }
}

it('pauses on human review and resumes to completion', function () {
    $graph = GraphBuilder::create('human_review')
        ->addNode('start', HRStartNode::class)
        ->addNode('respond', HRRespondNode::class)
        ->addNode('human_review', HRHumanReviewNode::class)
        ->addNode('end', HREndNode::class)
        ->addEdge('start', 'respond')
        ->addEdge('respond', 'human_review')
        ->addEdge('human_review', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('human_review')
        ->build();

    $executor = new GraphExecutor();
    \Shahzad\AgenticGraph\Graph\GraphRegistry::register('human_review', $graph);
    $state = new State(['message' => 'Complex billing issue']);
    $result = $executor->executeUntil($graph, $state, fn($s) => $s->get('needs_review') === true);
    expect($result['checkpointId'])->not()->toBeNull();

    $storage = new DatabaseStorage();
    $checkpoint = $storage->load($result['checkpointId']);
    $checkpoint->state = $checkpoint->state->set('approved', true);
    $storage->save($checkpoint);

    $final = $executor->resume($result['checkpointId']);
    expect($final->get('completed'))->toBeTrue();
});