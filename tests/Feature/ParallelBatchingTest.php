<?php

use Illuminate\Support\Facades\Bus;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;

class PBStart extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

class PBNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state;
    }
}

it('uses Bus::batch for parallel execution', function () {
    Bus::fake();
    $graph = GraphBuilder::create('parallel-batch')
        ->addNode('start', PBStart::class)
        ->addNode('branch1', PBNode::class)
        ->addNode('branch2', PBNode::class)
        ->addNode('end', PBNode::class)
        ->addConditionalEdge('start', new class implements \Shahzad\AgenticGraph\Contracts\RouterInterface
        {
            public function route(\Shahzad\AgenticGraph\Contracts\StateContract $state): string|array
            {
                return ['branch1', 'branch2'];
            }

            public function getAvailableRoutes(): array
            {
                return ['branch1', 'branch2'];
            }

            public function getDefaultRoute(): ?string
            {
                return null;
            }

            public function validateRoute(string $route): bool
            {
                return in_array($route, ['branch1', 'branch2'], true);
            }
        })
        ->addEdge('branch1', 'end')
        ->addEdge('branch2', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    GraphRegistry::register('parallel-batch', $graph);
    $job = new ExecuteGraphJob('parallel-batch', 'exec-batch', new State);
    $job->handle(new GraphExecutor);
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
});
