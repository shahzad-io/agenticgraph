<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Events\NodeResumed;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

uses()->group('e2e');

// Test nodes for interrupt workflow
class GenerateContentNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $topic = $state->get('topic', 'default topic');

        return $state->set('generated_content', "Content about: {$topic}");
    }
}

class ApprovalInterruptNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        // This runs AFTER approval
        return $state->set('content_reviewed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Content requires human approval before publishing';
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

class PublishNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('published', true);
    }
}

/**
 * D17: onResume deliberately does not satisfy shouldInterrupt — entry gate is skipped on resume.
 */
class ApprovalInterruptNodeWithoutGateSatisfaction extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('content_reviewed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Content requires human approval before publishing';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $state->set('approval_comments', $humanInput['comments'] ?? null);
    }
}

it('executes full interrupt workflow with approval', function () {
    $graph = GraphBuilder::create('approval-workflow')
        ->addNode('generate', GenerateContentNode::class)
        ->addNode('approve', ApprovalInterruptNode::class)
        ->addNode('publish', PublishNode::class)
        ->addEdge('generate', 'approve')
        ->addEdge('approve', 'publish')
        ->setEntryPoint('generate')
        ->addExitPoint('publish')
        ->build();

    $executor = new GraphExecutor;
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('approval-workflow', $graph);

    $state = new State(['topic' => 'AI trends']);

    // Execute - should interrupt at approval node
    $result = $executor->execute($graph, $state);

    expect($result->hasInterrupt())->toBeTrue();
    expect($result->get('generated_content'))->toBe('Content about: AI trends');
    expect($result->get('approved'))->toBeNull();

    $checkpointId = $result->getInterruptCheckpointId();
    expect($checkpointId)->not->toBeNull();

    // Simulate human approval
    $humanInput = [
        'approved' => true,
        'comments' => 'Great content!',
    ];

    // Resume execution
    $final = $executor->resumeWithInput($checkpointId, $humanInput);

    expect($final->get('published'))->toBeTrue();
    expect($final->get('approved'))->toBeTrue();
    expect($final->get('approval_comments'))->toBe('Great content!');
    expect($final->get('content_reviewed'))->toBeTrue();
    expect($final->hasInterrupt())->toBeFalse();
});

it('executes full interrupt workflow with rejection', function () {
    $graph = GraphBuilder::create('approval-workflow-rejection')
        ->addNode('generate', GenerateContentNode::class)
        ->addNode('approve', ApprovalInterruptNode::class)
        ->addNode('publish', PublishNode::class)
        ->addEdge('generate', 'approve')
        ->addEdge('approve', 'publish')
        ->setEntryPoint('generate')
        ->addExitPoint('publish')
        ->build();

    $executor = new GraphExecutor;
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('approval-workflow-rejection', $graph);

    $state = new State(['topic' => 'Controversial topic']);

    // Execute - should interrupt at approval node
    $result = $executor->execute($graph, $state);

    expect($result->hasInterrupt())->toBeTrue();
    $checkpointId = $result->getInterruptCheckpointId();

    // Simulate human rejection
    $humanInput = [
        'approved' => false,
        'comments' => 'Needs revision - tone is too negative',
    ];

    // Resume execution
    $final = $executor->resumeWithInput($checkpointId, $humanInput);

    // Graph continues even with rejection
    expect($final->get('published'))->toBeTrue();
    expect($final->get('approved'))->toBeFalse;
    expect($final->get('approval_comments'))->toBe('Needs revision - tone is too negative');
    expect($final->hasInterrupt())->toBeFalse();
});

it('throws exception when resuming non-interrupt checkpoint', function () {
    $executor = new GraphExecutor;

    // Create a graph and execute to create a regular checkpoint
    $graph = GraphBuilder::create('regular-workflow')
        ->addNode('step1', GenerateContentNode::class)
        ->addNode('step2', PublishNode::class)
        ->addEdge('step1', 'step2')
        ->setEntryPoint('step1')
        ->addExitPoint('step2')
        ->build();

    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('regular-workflow', $graph);

    // Manually create a regular checkpoint (not interrupted)
    $checkpoint = new Checkpoint;
    $checkpoint->id = 'cp-test-non-interrupt-123';
    $checkpoint->graphName = 'regular-workflow';
    $checkpoint->executionId = 'exec-test-123';
    $checkpoint->state = new State(['data' => 'test']);
    $checkpoint->currentNode = 'step1';
    $checkpoint->metadata = []; // No 'interrupted' flag
    $checkpoint->createdAt = new \DateTimeImmutable;

    $storage = app(StorageInterface::class);
    $checkpointId = $storage->save($checkpoint);

    expect(fn () => $executor->resumeWithInput($checkpointId, []))
        ->toThrow(\InvalidArgumentException::class, 'not an interrupt checkpoint');
});

it('throws exception when checkpoint not found', function () {
    $executor = new GraphExecutor;

    expect(fn () => $executor->resumeWithInput('non-existent-checkpoint-id-12345', []))
        ->toThrow(\RuntimeException::class, 'not found');
});

it('dispatches interrupt and resume events during execution', function () {
    Event::fake([NodeInterrupted::class, NodeResumed::class]);

    $graph = GraphBuilder::create('event-test-workflow')
        ->addNode('generate', GenerateContentNode::class)
        ->addNode('approve', ApprovalInterruptNode::class)
        ->addNode('publish', PublishNode::class)
        ->addEdge('generate', 'approve')
        ->addEdge('approve', 'publish')
        ->setEntryPoint('generate')
        ->addExitPoint('publish')
        ->build();

    $executor = new GraphExecutor;
    app(\Shahzad\AgenticGraph\Graph\GraphRegistry::class)->register('event-test-workflow', $graph);

    $state = new State(['topic' => 'Event testing']);

    // Execute - should interrupt
    $result = $executor->execute($graph, $state);

    // Assert interrupt event was dispatched
    Event::assertDispatched(NodeInterrupted::class, function ($event) {
        return $event->nodeName === 'approve'
            && $event->reason === 'Content requires human approval before publishing'
            && $event->checkpointId !== null;
    });

    // Resume should not have been dispatched yet
    Event::assertNotDispatched(NodeResumed::class);

    $checkpointId = $result->getInterruptCheckpointId();

    // Resume execution
    $executor->resumeWithInput($checkpointId, ['approved' => true]);

    // Assert resume event was dispatched
    Event::assertDispatched(NodeResumed::class, function ($event) use ($checkpointId) {
        return $event->nodeName === 'approve'
            && $event->checkpointId === $checkpointId;
    });
});

it('resumes past entry without re-gating when onResume does not satisfy shouldInterrupt (D17)', function () {
    $graph = GraphBuilder::create('approval-workflow-d17-mechanism')
        ->addNode('generate', GenerateContentNode::class)
        ->addNode('approve', ApprovalInterruptNodeWithoutGateSatisfaction::class)
        ->addNode('publish', PublishNode::class)
        ->addEdge('generate', 'approve')
        ->addEdge('approve', 'publish')
        ->setEntryPoint('generate')
        ->addExitPoint('publish')
        ->build();

    AgenticGraph::register('approval-workflow-d17-mechanism', $graph);

    $handle = AgenticGraph::execute('approval-workflow-d17-mechanism', new State(['topic' => 'D17']));
    $checkpointId = $handle->getFinalState()->getInterruptCheckpointId();

    expect($handle->getFinalState()->has('approved'))->toBeFalse();

    $final = AgenticGraph::resumeInterrupt($checkpointId, [
        'comments' => 'Proceed without setting approved',
    ]);

    expect($final->get('content_reviewed'))->toBeTrue()
        ->and($final->get('published'))->toBeTrue()
        ->and($final->get('approval_comments'))->toBe('Proceed without setting approved')
        ->and($final->has('approved'))->toBeFalse()
        ->and($final->hasInterrupt())->toBeFalse();
});

it('resumes interrupt via facade using checkpoint id', function () {
    $graph = GraphBuilder::create('approval-workflow-facade')
        ->addNode('generate', GenerateContentNode::class)
        ->addNode('approve', ApprovalInterruptNode::class)
        ->addNode('publish', PublishNode::class)
        ->addEdge('generate', 'approve')
        ->addEdge('approve', 'publish')
        ->setEntryPoint('generate')
        ->addExitPoint('publish')
        ->build();

    AgenticGraph::register('approval-workflow-facade', $graph);

    $handle = AgenticGraph::execute('approval-workflow-facade', new State(['topic' => 'Facade']));
    $checkpointId = $handle->getFinalState()->getInterruptCheckpointId();

    $final = AgenticGraph::resumeInterrupt($checkpointId, [
        'approved' => true,
        'comments' => 'OK',
    ]);

    expect($final->get('published'))->toBeTrue()
        ->and($final->get('approved'))->toBeTrue()
        ->and($final->get('approval_comments'))->toBe('OK')
        ->and($final->hasInterrupt())->toBeFalse();
});

it('resumes interrupt via facade using execution id', function () {
    Bus::fake();

    $graph = GraphBuilder::create('approval-workflow-resume-id')
        ->addNode('generate', GenerateContentNode::class)
        ->addNode('approve', ApprovalInterruptNode::class)
        ->addNode('publish', PublishNode::class)
        ->addEdge('generate', 'approve')
        ->addEdge('approve', 'publish')
        ->setEntryPoint('generate')
        ->addExitPoint('publish')
        ->build();

    AgenticGraph::register('approval-workflow-resume-id', $graph);

    $handle = AgenticGraph::execute('approval-workflow-resume-id', new State(['topic' => 'Facade']));
    $executionId = $handle->id();

    $pending = InterruptRecord::query()
        ->forExecution($executionId)
        ->pending()
        ->latest('id')
        ->first();

    expect($pending)->not->toBeNull();

    $result = AgenticGraph::resume($executionId, [
        'approved' => true,
        'comments' => 'OK',
    ]);

    Bus::assertDispatched(ResumeExecutionJob::class);

    expect($result->alreadyResolved)->toBeFalse()
        ->and($result->interrupt?->status())->toBe('resolving')
        ->and($result->interrupt?->executionId())->toBe($executionId);
});

it('exposes pending interrupt on the execution handle', function () {
    $graph = GraphBuilder::create('approval-workflow-pending')
        ->addNode('generate', GenerateContentNode::class)
        ->addNode('approve', ApprovalInterruptNode::class)
        ->addNode('publish', PublishNode::class)
        ->addEdge('generate', 'approve')
        ->addEdge('approve', 'publish')
        ->setEntryPoint('generate')
        ->addExitPoint('publish')
        ->build();

    AgenticGraph::register('approval-workflow-pending', $graph);

    $handle = AgenticGraph::execute('approval-workflow-pending', new State(['topic' => 'Facade']));
    $interrupt = $handle->pendingInterrupt();

    expect($interrupt)->not->toBeNull()
        ->and($interrupt?->status())->toBe('pending')
        ->and($interrupt?->executionId())->toBe($handle->id());
});

it('throws helpful error when interrupt checkpoint is missing', function () {
    expect(fn () => AgenticGraph::resumeInterrupt('missing-interrupt-checkpoint', []))
        ->toThrow(\RuntimeException::class, 'Persist the interrupt checkpoint id');
});

it('throws helpful error when checkpoint is not an interrupt checkpoint', function () {
    $graph = GraphBuilder::create('non-interrupt-workflow-facade')
        ->addNode('step1', GenerateContentNode::class)
        ->addNode('step2', PublishNode::class)
        ->addEdge('step1', 'step2')
        ->setEntryPoint('step1')
        ->addExitPoint('step2')
        ->build();

    AgenticGraph::register('non-interrupt-workflow-facade', $graph);

    $checkpoint = new Checkpoint;
    $checkpoint->id = 'cp-test-non-interrupt-facade';
    $checkpoint->graphName = 'non-interrupt-workflow-facade';
    $checkpoint->executionId = 'exec-test-facade';
    $checkpoint->state = new State(['data' => 'test']);
    $checkpoint->currentNode = 'step1';
    $checkpoint->metadata = [];
    $checkpoint->createdAt = new \DateTimeImmutable;

    $storage = app(StorageInterface::class);
    $checkpointId = $storage->save($checkpoint);

    expect(fn () => AgenticGraph::resumeInterrupt($checkpointId, []))
        ->toThrow(\InvalidArgumentException::class, 'Use the checkpoint id from State::getInterruptCheckpointId()');
});
