<?php

use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\InterruptPending;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Events\NodeResumed;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

uses()->group('integration');

beforeEach(function () {
    Event::fake([NodeInterrupted::class, NodeResumed::class]);
    $this->events = Event::getFacadeRoot();
});

class IntegrationApprovalNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('after_interrupt', true);
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

class SimpleProcessNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('processed', true);
    }
}

class PayloadInterruptNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('after_interrupt', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return true;
    }

    public function getInterruptPayload(StateContract $state): ?array
    {
        return [
            'fields' => ['approved', 'comments'],
            'title' => 'Needs review',
        ];
    }
}

it('detects interrupt state in returned state', function () {
    $state = new State(['data' => 'test']);
    $state = $state->setInterrupt('Needs review', 'cp-123');

    expect($state->hasInterrupt())->toBeTrue;
    expect($state->getInterruptCheckpointId())->toBe('cp-123');
    expect($state->get('data'))->toBe('test');
});

it('state can be cleared of interrupt', function () {
    $state = new State(['data' => 'test']);
    $state = $state->setInterrupt('Needs review', 'cp-123');
    $state = $state->clearInterrupt();

    expect($state->hasInterrupt())->toBeFalse;
    expect($state->getInterruptCheckpointId())->toBeNull;
    expect($state->get('data'))->toBe('test');
});

it('creates interrupt checkpoint correctly', function () {
    $checkpoint = new Checkpoint;
    $checkpoint->graphName = 'test-graph';
    $checkpoint->executionId = 'exec-123';
    $checkpoint->state = new State(['step' => 'approval']);
    $checkpoint->currentNode = 'approval-node';
    $checkpoint->metadata['interrupted'] = true;
    $checkpoint->metadata['interrupt_reason'] = 'Needs human approval';
    $checkpoint->createdAt = new DateTimeImmutable;

    expect($checkpoint->metadata['interrupted'])->toBeTrue;
    expect($checkpoint->metadata['interrupt_reason'])->toBe('Needs human approval');
    expect($checkpoint->currentNode)->toBe('approval-node');
});

it('prism node interrupts when condition is met', function () {
    $node = new IntegrationApprovalNode;
    $state = new State;

    expect($node->shouldInterrupt($state))->toBeTrue;

    $state = $state->set('approved', true);
    expect($node->shouldInterrupt($state))->toBeFalse;
});

it('prism node processes human input on resume', function () {
    $node = new IntegrationApprovalNode;
    $state = new State;

    $result = $node->onResume($state, [
        'approved' => true,
        'comments' => 'LGTM',
    ]);

    expect($result->get('approved'))->toBeTrue;
    expect($result->get('approval_comments'))->toBe('LGTM');
});

it('node interrupted event is dispatched correctly', function () {
    $event = new NodeInterrupted(
        executionId: 'exec-123',
        nodeName: 'approval-node',
        reason: 'Needs approval',
        checkpointId: 'cp-abc',
        stateSnapshot: ['data' => 'test'],
        interruptedAt: new DateTimeImmutable
    );

    expect($event->executionId)->toBe('exec-123');
    expect($event->nodeName)->toBe('approval-node');
    expect($event->reason)->toBe('Needs approval');
    expect($event->checkpointId)->toBe('cp-abc');
    expect($event->stateSnapshot)->toBe(['data' => 'test']);
});

it('node resumed event is dispatched correctly', function () {
    $event = new NodeResumed(
        executionId: 'exec-123',
        nodeName: 'approval-node',
        checkpointId: 'cp-abc',
        stateSnapshot: ['approved' => true],
        resumedAt: new DateTimeImmutable
    );

    expect($event->executionId)->toBe('exec-123');
    expect($event->nodeName)->toBe('approval-node');
    expect($event->checkpointId)->toBe('cp-abc');
    expect($event->stateSnapshot)->toBe(['approved' => true]);
});

it('non-prism nodes do not interrupt', function () {
    // Create a simple non-PrismNode
    $node = new class extends \Shahzad\AgenticGraph\Nodes\AbstractNode
    {
        public function execute(StateContract $state): StateContract
        {
            return $state->set('done', true);
        }
    };

    $state = new State;

    // Non-PrismNode does not have shouldInterrupt method
    expect(method_exists($node, 'shouldInterrupt'))->toBeFalse;
});

it('handles multiple interrupt nodes in sequence', function () {
    $state = new State;

    // First interrupt
    $state = $state->setInterrupt('First approval', 'cp-1');
    expect($state->hasInterrupt())->toBeTrue;
    expect($state->getInterruptCheckpointId())->toBe('cp-1');

    // Clear and second interrupt
    $state = $state->clearInterrupt();
    $state = $state->setInterrupt('Second approval', 'cp-2');
    expect($state->getInterruptCheckpointId())->toBe('cp-2');
});

it('state immutability preserved through interrupt cycle', function () {
    $state1 = new State(['original' => 'data']);
    $state2 = $state1->setInterrupt('Test', 'cp-1');
    $state3 = $state2->clearInterrupt();

    // All should be different instances
    expect($state1)->not->toBe($state2);
    expect($state2)->not->toBe($state3);
    expect($state1)->not->toBe($state3);

    // Original should never have interrupt
    expect($state1->hasInterrupt())->toBeFalse;

    // state2 should have interrupt
    expect($state2->hasInterrupt())->toBeTrue;

    // state3 should not have interrupt but preserves data
    expect($state3->hasInterrupt())->toBeFalse;
    expect($state3->get('original'))->toBe('data');
});

it('persists interrupt records with payload data', function () {
    $graph = GraphBuilder::create('interrupt-payload-graph')
        ->addNode('review', PayloadInterruptNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    $executor = new GraphExecutor;
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('interrupt-payload-graph', $graph);

    $state = new State(['topic' => 'Payload']);
    $result = $executor->execute($graph, $state);

    expect($result->hasInterrupt())->toBeTrue();

    $record = InterruptRecord::query()
        ->where('graph_name', 'interrupt-payload-graph')
        ->latest('id')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->status())->toBe('pending')
        ->and($record->interruptPayload())->toMatchArray([
            'fields' => ['approved', 'comments'],
            'title' => 'Needs review',
        ]);
});

it('returns interrupt status with latest state', function () {
    $graph = GraphBuilder::create('interrupt-status-graph')
        ->addNode('review', IntegrationApprovalNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('interrupt-status-graph', $graph);

    $state = new State(['topic' => 'Status']);
    $handle = AgenticGraph::execute('interrupt-status-graph', $state);
    $handle->refresh();

    $status = AgenticGraph::interruptStatus($handle->id());

    expect($status->status)->toBe(ExecutionStatus::INTERRUPTED);
    expect($status->interrupt)->not->toBeNull();
    expect($status->latestState)->not->toBeNull()
        ->and($status->latestState->toArray()['_interrupt']['reason'])->toBe('Content requires approval');
});

it('supports interrupt record scopes', function () {
    $record = InterruptRecord::query()->create([
        'execution_id' => 'exec-scope-1',
        'checkpoint_id' => 'chk-scope-1',
        'graph_name' => 'scope-graph',
        'node_name' => 'node-a',
        'reason' => 'Needs review',
        'status' => 'pending',
        'metadata' => ['user_id' => 'user-1'],
    ]);

    $pending = InterruptRecord::query()->pending()->get();
    $forUser = InterruptRecord::query()->forUser('user-1')->get();

    expect($pending->pluck('id')->all())->toContain($record->id)
        ->and($forUser->pluck('id')->all())->toContain($record->id);
});

it('dispatches interrupt pending event when interrupt is persisted', function () {
    Event::fake([InterruptPending::class]);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.interrupts.broadcast_enabled', true);

    $graph = GraphBuilder::create('interrupt-pending-graph')
        ->addNode('approve', IntegrationApprovalNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build();

    $executor = new GraphExecutor;
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('interrupt-pending-graph', $graph);

    $state = new State(['topic' => 'Pending']);
    $result = $executor->execute($graph, $state);

    $checkpointId = $result->getInterruptCheckpointId();

    Event::assertDispatched(InterruptPending::class, function ($event) use ($checkpointId) {
        return $event->graphName === 'interrupt-pending-graph'
            && $event->nodeName === 'approve'
            && $event->reason === 'Content requires approval'
            && $event->checkpointId === $checkpointId
            && $event->payload === null;
    });
});
