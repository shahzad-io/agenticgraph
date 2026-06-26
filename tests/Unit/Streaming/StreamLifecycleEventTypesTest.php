<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamLifecycleEventTypes;

class LifecycleConstantInterruptNode extends PrismNode
{
    protected ?string $name = 'approve';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('processed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Needs approval';
    }
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
});

test('node interrupt constant equals wire value and is a lifecycle framework type', function () {
    expect(StreamLifecycleEventTypes::NODE_INTERRUPT)->toBe('node_interrupt')
        ->and(StreamLifecycleEventTypes::isLifecycleOrFrameworkType(StreamLifecycleEventTypes::NODE_INTERRUPT))->toBeTrue();
});

it('emits node_interrupt using the StreamLifecycleEventTypes constant', function () {
    $graphName = 'lifecycle-constant-interrupt-'.uniqid();
    $graph = GraphBuilder::create($graphName)
        ->addNode('approve', LifecycleConstantInterruptNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    AgenticGraph::register($graphName, $graph);

    $handle = AgenticGraph::execute(
        $graphName,
        new State(['prompt' => 'approve']),
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR, interrupts: true)
    );

    $types = array_map(
        fn ($event) => $event->type(),
        iterator_to_array($handle->stream()->inline ?? new ArrayIterator([]))
    );

    expect($types)->toContain(StreamLifecycleEventTypes::NODE_INTERRUPT);
});
