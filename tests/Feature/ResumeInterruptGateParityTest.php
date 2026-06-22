<?php

use Illuminate\Support\Facades\Bus;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;
use Shahzad\AgenticGraph\State\State;

class D17ParityStartNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('started', true);
    }
}

/**
 * Resume-entry node: shouldInterrupt stays true after onResume (D17 entry-skip proof).
 */
class D17ParityEntryNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('entry_runs', ((int) $state->get('entry_runs', 0)) + 1);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->get('entry_passed', false);
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Entry gate';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $state->set('resume_marker', $humanInput['marker'] ?? 'applied');
    }
}

class D17ParityDownstreamNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state
            ->set('downstream_ran', true)
            ->set('route_to_entry', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->get('downstream_passed', false);
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Downstream gate';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $state->set('downstream_passed', (bool) ($humanInput['downstream_passed'] ?? false));
    }
}

class D17ParityDoneNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('finished', true);
    }
}

class D17ParityLoopRouter implements RouterInterface
{
    public function route(StateContract $state): string
    {
        if ($state->get('route_to_entry', false) && ! $state->get('loop_returned', false)) {
            return 'entry';
        }

        return 'done';
    }

    public function getAvailableRoutes(): array
    {
        return ['entry', 'done'];
    }

    public function getDefaultRoute(): ?string
    {
        return 'done';
    }

    public function validateRoute(string $route): bool
    {
        return in_array($route, $this->getAvailableRoutes(), true);
    }
}

function registerD17ParityGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('start', D17ParityStartNode::class)
        ->addNode('entry', D17ParityEntryNode::class)
        ->addNode('downstream', D17ParityDownstreamNode::class)
        ->addNode('done', D17ParityDoneNode::class)
        ->addEdge('start', 'entry')
        ->addEdge('entry', 'downstream')
        ->addConditionalEdge('downstream', D17ParityLoopRouter::class)
        ->setEntryPoint('start')
        ->addExitPoint('done')
        ->build();

    AgenticGraph::register($name, $graph);
}

function pendingInterruptNodeFor(string $executionId): ?string
{
    return InterruptRecord::query()
        ->forExecution($executionId)
        ->pending()
        ->latest('id')
        ->first()
        ?->nodeName();
}

function runExecuteGraphJob(string $executionId): void
{
    $job = null;

    Bus::assertDispatched(ExecuteGraphJob::class, function (ExecuteGraphJob $dispatched) use ($executionId, &$job): bool {
        if ($dispatched->executionId !== $executionId) {
            return false;
        }

        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ExecuteGraphJob::class);
    $job->handle(app(GraphExecutor::class), app(GraphRegistry::class));
}

function runResumeExecutionJobFor(string $executionId): void
{
    $job = null;

    Bus::assertDispatched(ResumeExecutionJob::class, function (ResumeExecutionJob $dispatched) use ($executionId, &$job): bool {
        $interrupt = InterruptRecord::find($dispatched->interruptId);

        if ($interrupt?->executionId() !== $executionId) {
            return false;
        }

        $job = $dispatched;

        return true;
    });

    expect($job)->toBeInstanceOf(ResumeExecutionJob::class);
    $job->handle(
        app(\Shahzad\AgenticGraph\Contracts\InterruptServiceInterface::class),
        app(\Shahzad\AgenticGraph\Streaming\StreamResumption::class)
    );
}

/**
 * @return array{executionId: string, state: StateContract, handle: \Shahzad\AgenticGraph\Execution\ExecutionHandle}
 */
function startD17ParityExecution(string $graphName, ExecutionOptions $options): array
{
    if ($options->mode === ExecutionMode::ASYNC) {
        Bus::fake();
    }

    $handle = AgenticGraph::execute($graphName, new State, $options);

    if ($options->mode === ExecutionMode::ASYNC) {
        runExecuteGraphJob($handle->id());
        $handle = $handle->refresh();
    }

    return [
        'executionId' => $handle->id(),
        'state' => $handle->getFinalState(),
        'handle' => $handle,
    ];
}

function latestInterruptCheckpointId(string $executionId): string
{
    $checkpointId = InterruptRecord::query()
        ->forExecution($executionId)
        ->pending()
        ->latest('id')
        ->first()
        ?->checkpointId();

    expect($checkpointId)->not->toBeNull();

    return (string) $checkpointId;
}

function stateAfterAsyncResume(string $executionId): StateContract
{
    $pending = InterruptRecord::query()
        ->forExecution($executionId)
        ->pending()
        ->latest('id')
        ->first();

    if ($pending !== null) {
        $checkpoint = app(StorageInterface::class)->load((string) $pending->checkpointId());
        expect($checkpoint)->not->toBeNull();

        $state = $checkpoint->state;
        $reason = $pending->reason() ?? 'Interrupted';

        return $state->setInterrupt($reason, (string) $pending->checkpointId());
    }

    return AgenticGraph::getExecution($executionId)->refresh()->getFinalState();
}

function resumeD17ParityExecution(
    string $executionId,
    ExecutionOptions $options,
    mixed $input
): StateContract {
    if ($options->mode === ExecutionMode::ASYNC) {
        Bus::fake();
        AgenticGraph::resume($executionId, $input);
        runResumeExecutionJobFor($executionId);

        return stateAfterAsyncResume($executionId);
    }

    $checkpointId = latestInterruptCheckpointId($executionId);

    if ($options->streaming !== StreamingMode::NONE) {
        return app(StreamResumption::class)->resumeStream(
            $checkpointId,
            $input,
            new StreamOptions(delivery: $options->streaming->value, interrupts: true)
        )->getFinalState();
    }

    return app(GraphExecutor::class)->resumeWithInput($checkpointId, $input);
}

function assertD17ResumeGateParity(ExecutionOptions $options, string $suffix): void
{
    $graphName = 'd17-parity-'.$suffix;
    registerD17ParityGraph($graphName);

    $started = startD17ParityExecution($graphName, $options);
    $executionId = $started['executionId'];
    $handle = $started['handle'];

    expect($started['state']->hasInterrupt())->toBeTrue()
        ->and($started['state']->get('entry_runs', 0))->toBe(0)
        ->and(pendingInterruptNodeFor($executionId))->toBe('entry');

    $afterEntryResume = resumeD17ParityExecution(
        $executionId,
        $options,
        ['marker' => 'entry-resume']
    );

    expect($afterEntryResume->get('resume_marker'))->toBe('entry-resume')
        ->and($afterEntryResume->get('entry_runs'))->toBe(1)
        ->and($afterEntryResume->hasInterrupt())->toBeTrue()
        ->and(pendingInterruptNodeFor($executionId))->toBe('downstream');

    $afterDownstreamResume = resumeD17ParityExecution(
        $executionId,
        $options,
        ['downstream_passed' => true]
    );

    expect($afterDownstreamResume->get('downstream_ran'))->toBeTrue()
        ->and($afterDownstreamResume->get('route_to_entry'))->toBeTrue()
        ->and($afterDownstreamResume->hasInterrupt())->toBeTrue()
        ->and($afterDownstreamResume->get('entry_runs'))->toBe(1)
        ->and(pendingInterruptNodeFor($executionId))->toBe('entry');
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
});

it('enforces D17 resume gate parity on SYNC+NONE', function () {
    assertD17ResumeGateParity(
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE),
        'sync-none'
    );
});

it('enforces D17 resume gate parity on SYNC+ITERATOR streaming', function () {
    assertD17ResumeGateParity(
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR),
        'sync-iterator'
    );
});

it('enforces D17 resume gate parity on ASYNC+NONE', function () {
    assertD17ResumeGateParity(
        new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::NONE),
        'async-none'
    );
});

it('fails step-3 when streaming re-gates entry without D17 skip', function () {
    $graph = GraphBuilder::create('d17-streaming-regate-probe')
        ->addNode('entry', D17ParityEntryNode::class)
        ->setEntryPoint('entry')
        ->addExitPoint('entry')
        ->build();

    AgenticGraph::register('d17-streaming-regate-probe', $graph);

    $handle = AgenticGraph::execute(
        'd17-streaming-regate-probe',
        new State,
        new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR)
    );

    expect($handle->getFinalState()->hasInterrupt())->toBeTrue();

    $resumed = $handle->resume(['marker' => 'probe']);

    expect($resumed->getFinalState()->get('entry_runs', 0))
        ->toBe(1, 'D17: entry body must run once after resume even when shouldInterrupt remains true');
});
