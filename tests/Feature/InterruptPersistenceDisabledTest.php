<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Events\NodeInterrupted;
use Shahzad\AgenticGraph\Exceptions\InterruptPersistenceDisabledException;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class PersistenceDisabledApprovalNode extends PrismNode
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
        return 'Persistence-disabled interrupt regression';
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

class PersistenceDisabledPublishNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('published', true);
    }
}

function disableInterruptPersistence(): void
{
    config()->set('agenticgraph.interrupts.persistence_enabled', false);
    app()->forgetInstance(InterruptServiceInterface::class);
}

function enableInterruptPersistence(): void
{
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    app()->forgetInstance(InterruptServiceInterface::class);
}

function registerPersistenceDisabledGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('review', PersistenceDisabledApprovalNode::class)
        ->addNode('publish', PersistenceDisabledPublishNode::class)
        ->addEdge('review', 'publish')
        ->setEntryPoint('review')
        ->addExitPoint('publish')
        ->build();

    AgenticGraph::register($name, $graph);
}

it('throws when AgenticGraph resume is called with persistence disabled', function () {
    disableInterruptPersistence();

    expect(fn () => AgenticGraph::resume('exec-no-persistence', ['approved' => true]))
        ->toThrow(InterruptPersistenceDisabledException::class);
});

it('throws when an async handle resume delegates to AgenticGraph resume with persistence disabled', function () {
    disableInterruptPersistence();
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');

    Event::fake([NodeInterrupted::class]);
    Bus::fake();

    $graphName = 'async-handle-persistence-disabled-'.Str::uuid();
    registerPersistenceDisabledGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::ASYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    $job = null;
    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use ($handle, &$job): bool {
        if ($dispatched->executionId !== $handle->id()) {
            return false;
        }
        $job = $dispatched;

        return true;
    });
    $job->handle(app(GraphExecutor::class), app(GraphRegistry::class));

    $handle = $handle->refresh();
    expect($handle->status())->toBe(ExecutionStatus::INTERRUPTED);

    expect(fn () => $handle->resume(['approved' => true, 'comments' => 'ok']))
        ->toThrow(InterruptPersistenceDisabledException::class);
});

it('logs a warning when an interrupt fires with persistence disabled', function () {
    disableInterruptPersistence();
    config()->set('agenticgraph.checkpoint.enabled', true);
    Log::spy();

    $graphName = 'interrupt-warning-persistence-disabled-'.Str::uuid();
    registerPersistenceDisabledGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    expect($handle->status())->toBe(ExecutionStatus::INTERRUPTED);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'AgenticGraph::resume() will not be available')
                && isset($context['execution_id'], $context['checkpoint_id']);
        });
});

it('still resumes via sync handle when persistence is disabled', function () {
    disableInterruptPersistence();
    config()->set('agenticgraph.checkpoint.enabled', true);

    $graphName = 'resume-handle-persistence-disabled-'.Str::uuid();
    registerPersistenceDisabledGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    expect($handle->getFinalState()->getInterruptCheckpointId())->not->toBeNull();

    $resumed = $handle->resume([
        'approved' => true,
        'comments' => 'handle path',
    ]);

    expect($resumed->getFinalState()->get('published'))->toBeTrue()
        ->and($resumed->getFinalState()->get('approved'))->toBeTrue()
        ->and($resumed->getFinalState()->hasInterrupt())->toBeFalse();
});

it('returns a benign ResumeResult when persistence is enabled but no pending interrupt exists', function () {
    enableInterruptPersistence();
    Bus::fake();

    $result = AgenticGraph::resume('missing-or-resolved-exec', ['approved' => true]);

    expect($result->interrupt)->toBeNull()
        ->and($result->alreadyResolved)->toBeFalse();

    Bus::assertNotDispatched(ResumeExecutionJob::class);
});

it('returns already resolved ResumeResult when persistence is enabled and interrupt was resolved', function () {
    enableInterruptPersistence();
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');

    $graphName = 'resume-already-resolved-'.Str::uuid();
    registerPersistenceDisabledGraph($graphName);

    $handle = AgenticGraph::execute(
        $graphName,
        new State,
        new ExecutionOptions(
            mode: ExecutionMode::SYNC,
            streaming: StreamingMode::NONE,
            interrupts: true
        )
    );

    $handle->resume(['approved' => true, 'comments' => 'done']);

    Bus::fake();

    $result = AgenticGraph::resume($handle->id(), ['approved' => true]);

    expect($result->alreadyResolved)->toBeTrue()
        ->and($result->interrupt)->not->toBeNull();

    Bus::assertNotDispatched(ResumeExecutionJob::class);
});
