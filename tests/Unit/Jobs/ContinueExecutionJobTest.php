<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;

class CENodeA extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('a', true);
    }
}

class CENodeEnd extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

it('continues execution from a node', function () {
    $graph = GraphBuilder::create('ce-graph')
        ->addNode('a', CENodeA::class)
        ->addNode('end', CENodeEnd::class)
        ->addEdge('a', 'end')
        ->setEntryPoint('a')
        ->addExitPoint('end')
        ->build();
    GraphRegistry::register('ce-graph', $graph);
    $job = new ContinueExecutionJob('ce-graph', 'exec-2', new State(), 'a');
    $job->handle(new GraphExecutor());
    $list = (new DatabaseStorage())->list('ce-graph');
    expect($list)->not()->toBeEmpty();
});