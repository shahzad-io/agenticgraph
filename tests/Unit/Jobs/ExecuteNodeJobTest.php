<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class ENNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('ok', true);
    }
}

it('stores node job output', function () {
    $graph = GraphBuilder::create('job-graph')
        ->addNode('n', ENNode::class)
        ->setEntryPoint('n')
        ->addExitPoint('n')
        ->build();
    app(GraphRegistry::class)->register('test-graph', $graph);
    app(ExecutionRepository::class)->queued('exec-1', 'test-graph');
    $job = new ExecuteNodeJob('exec-1', 'n', (new State)->toArray(), 'test-graph');
    $job->handle(app(GraphRegistry::class), app(NodeMiddlewarePipeline::class));
    $cached = app(ExecutionRepository::class)->batchResults('exec-1')['n'] ?? null;
    expect($cached)->toBeArray()->and($cached['ok'])->toBeTrue();
});
