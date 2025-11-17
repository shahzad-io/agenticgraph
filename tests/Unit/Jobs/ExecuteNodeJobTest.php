<?php

use Illuminate\Support\Facades\Cache;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
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

it('caches node job output', function () {
    $graph = GraphBuilder::create('job-graph')
        ->addNode('n', ENNode::class)
        ->setEntryPoint('n')
        ->addExitPoint('n')
        ->build();
    $job = new ExecuteNodeJob('exec-1', 'n', new State(), $graph);
    $job->handle();
    $cached = Cache::get('agenticgraph:batch:exec-1:n');
    expect($cached)->toBeArray()->and($cached['ok'])->toBeTrue();
});