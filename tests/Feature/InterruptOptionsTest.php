<?php

use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class InterruptOptionsNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('executed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return true;
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Always interrupt for interrupt-options tests';
    }
}

class InterruptOptionsStreamNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('executed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return true;
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Always interrupt for interrupt-options streaming tests';
    }
}

function registerInterruptOptionsGraph(string $name, string $nodeClass): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', $nodeClass)
        ->setEntryPoint('start')
        ->addExitPoint('start')
        ->build();

    AgenticGraph::register($name, $graph);
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
});

it('completes SYNC+NONE without interrupting when interrupts is false', function () {
    Event::fake([NodeInterrupted::class]);
    registerInterruptOptionsGraph('interrupt-options-sync-none-false', InterruptOptionsNode::class);

    $handle = AgenticGraph::execute(
        'interrupt-options-sync-none-false',
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::NONE,
            interrupts: false
        )
    );

    $finalState = $handle->getFinalState();

    expect($finalState->get('executed'))->toBeTrue()
        ->and($finalState->hasInterrupt())->toBeFalse();

    Event::assertNotDispatched(NodeInterrupted::class);
});

it('interrupts SYNC+NONE when interrupts is true', function () {
    Event::fake([NodeInterrupted::class]);
    registerInterruptOptionsGraph('interrupt-options-sync-none-true', InterruptOptionsNode::class);

    $handle = AgenticGraph::execute(
        'interrupt-options-sync-none-true',
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    expect($handle->getFinalState()->hasInterrupt())->toBeTrue();
    Event::assertDispatched(NodeInterrupted::class);
});

it('completes SYNC+ITERATOR without interrupting when interrupts is false', function () {
    Event::fake([NodeInterrupted::class]);
    registerInterruptOptionsGraph('interrupt-options-sync-iterator-false', InterruptOptionsStreamNode::class);

    $handle = AgenticGraph::execute(
        'interrupt-options-sync-iterator-false',
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::ITERATOR,
            interrupts: false
        )
    );

    $finalState = $handle->getFinalState();

    expect($finalState->get('executed'))->toBeTrue()
        ->and($finalState->hasInterrupt())->toBeFalse();

    Event::assertNotDispatched(NodeInterrupted::class);
});

it('interrupts SYNC+ITERATOR when interrupts is true', function () {
    Event::fake([NodeInterrupted::class]);
    registerInterruptOptionsGraph('interrupt-options-sync-iterator-true', InterruptOptionsStreamNode::class);

    $handle = AgenticGraph::execute(
        'interrupt-options-sync-iterator-true',
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::ITERATOR,
            interrupts: true
        )
    );

    expect($handle->getFinalState()->hasInterrupt())->toBeTrue();
    Event::assertDispatched(NodeInterrupted::class);
});
