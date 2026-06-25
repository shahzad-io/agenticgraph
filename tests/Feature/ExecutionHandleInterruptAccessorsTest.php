<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionHandle;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class AccessorApprovalNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('reviewed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Content requires approval';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $this->handleApproval(
            $state,
            $humanInput['approved'] ?? false,
            $humanInput['comments'] ?? null
        );
    }
}

class AccessorCompleteNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }
}

function registerAccessorGraph(string $name, string $nodeClass): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('review', $nodeClass)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    AgenticGraph::register($name, $graph);
}

function assertInterruptedHandleAccessors(
    ExecutionHandle $handle,
    InterruptRecord $record
): void {
    expect($handle->isInterrupted())->toBeTrue()
        ->and($handle->interruptReason())->toBe($record->reason())
        ->and($handle->checkpointId())->toBe($record->checkpointId())
        ->and($handle->interruptedAtNode())->toBe($record->nodeName())
        ->and($handle->checkpointId())->toBe($handle->pendingInterrupt()?->checkpointId());
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
});

it('exposes interrupt accessors on a live interrupted handle', function () {
    registerAccessorGraph('accessor-live', AccessorApprovalNode::class);

    $handle = AgenticGraph::execute('accessor-live', new State(['topic' => 'live']));
    $record = $handle->pendingInterrupt();

    expect($record)->not->toBeNull();
    assertInterruptedHandleAccessors($handle, $record);
});

it('exposes interrupt accessors on a getExecution reconstructed handle', function () {
    registerAccessorGraph('accessor-reconstructed', AccessorApprovalNode::class);

    $handle = AgenticGraph::execute('accessor-reconstructed', new State(['topic' => 'reconstructed']));
    $record = $handle->pendingInterrupt();
    expect($record)->not->toBeNull();

    $reconstructed = AgenticGraph::getExecution($handle->id())->refresh();

    assertInterruptedHandleAccessors($reconstructed, $record);
});

it('returns null interrupt accessors on a completed handle without throwing', function () {
    registerAccessorGraph('accessor-complete', AccessorCompleteNode::class);

    $handle = AgenticGraph::execute('accessor-complete', new State);

    expect($handle->isInterrupted())->toBeFalse()
        ->and($handle->interruptReason())->toBeNull()
        ->and($handle->checkpointId())->toBeNull()
        ->and($handle->interruptedAtNode())->toBeNull();
});

it('returns interrupt details bundle matching the former interruptStatus shape', function () {
    registerAccessorGraph('accessor-details', AccessorApprovalNode::class);

    $handle = AgenticGraph::execute('accessor-details', new State(['topic' => 'details']));
    $handle->refresh();

    $details = AgenticGraph::interruptDetails($handle->id());

    expect($details->status)->toBe(ExecutionStatus::INTERRUPTED)
        ->and($details->interrupt)->not->toBeNull()
        ->and($details->interrupt->reason())->toBe('Content requires approval')
        ->and($details->latestState)->not->toBeNull()
        ->and($details->latestState->get('topic'))->toBe('details');
});

it('matches checkpointId accessor to the resume anchor on interrupted handles', function () {
    registerAccessorGraph('accessor-resume-anchor', AccessorApprovalNode::class);

    $handle = AgenticGraph::execute(
        'accessor-resume-anchor',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE)
    );

    $checkpointId = $handle->checkpointId();
    expect($checkpointId)->not->toBeNull()
        ->and($handle->pendingInterrupt()?->checkpointId())->toBe($checkpointId);

    $resumed = $handle->resume(['approved' => true, 'comments' => 'ok']);

    expect($resumed->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($resumed->checkpointId())->toBeNull();
});
