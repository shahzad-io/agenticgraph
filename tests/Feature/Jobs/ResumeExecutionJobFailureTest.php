<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

class ResumeFailureApprovalNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        if ($state->get('fail_after_approval') && $state->has('approved')) {
            throw new RuntimeException('provider error after approval');
        }

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

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $this->handleApproval($state, $humanInput['approved'] ?? false, null);
    }
}

class ResumeFailureBroadcaster implements Broadcaster
{
    /** @var list<array{event: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function auth($request)
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return null;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $this->sent[] = ['event' => $event, 'payload' => $payload];
    }
}

/**
 * Runs an async broadcast graph to its approval interrupt and approves it.
 *
 * @return array{execution_id: string, stream_id: string, job: ResumeExecutionJob, broadcaster: ResumeFailureBroadcaster, interrupted_seq: int}
 */
function resumeFailureInterruptedRun(string $graphName, bool $failAfterApproval = false): array
{
    AgenticGraph::register($graphName, GraphBuilder::create($graphName)
        ->addNode('approve', ResumeFailureApprovalNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build());

    $broadcaster = new ResumeFailureBroadcaster;
    app()->instance(Broadcaster::class, $broadcaster);
    Bus::fake();

    $handle = AgenticGraph::execute($graphName, new State(['fail_after_approval' => $failAfterApproval]), new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::BROADCAST,
        interrupts: true,
    ));

    $executeJob = Bus::dispatched(ExecuteGraphJob::class)->first(fn (ExecuteGraphJob $job) => $job->executionId === $handle->id());
    $executeJob->handle(app(GraphExecutor::class), app(GraphRegistry::class));

    $interruptedSeq = (int) app(StreamStoreInterface::class)->lastSeq((string) $handle->streamId());
    $handle->refresh()->resume(['approved' => true]);

    $job = Bus::dispatched(ResumeExecutionJob::class)
        ->first(fn (ResumeExecutionJob $job) => InterruptRecord::find($job->interruptId)?->executionId() === $handle->id());

    return [
        'execution_id' => $handle->id(),
        'stream_id' => (string) $handle->streamId(),
        'job' => $job,
        'broadcaster' => $broadcaster,
        'interrupted_seq' => $interruptedSeq,
    ];
}

/**
 * Runs the resume job as a worker would: handle(), and failed() when it throws.
 */
function resumeFailureRunJob(ResumeExecutionJob $job): void
{
    try {
        $job->handle(app(InterruptServiceInterface::class), app(StreamResumption::class));
    } catch (Throwable $e) {
        $job->failed($e);
    }
}

/**
 * @return list<string> stored event types after the interrupted run's terminal
 */
function resumeFailureEventsAfter(string $streamId, int $seq): array
{
    return array_map(
        fn (StreamEvent $event) => $event->type(),
        array_values(iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, $seq + 1, 100), false))
    );
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    app()->forgetInstance(StreamStoreInterface::class);
    Event::fake([GraphExecutionFailed::class]);
});

it('ends the execution and stream once when the resume fails before the run starts', function () {
    $run = resumeFailureInterruptedRun('resume-fail-before-run');
    $broadcastsBefore = count($run['broadcaster']->sent);
    app(GraphRegistry::class)->unregister('resume-fail-before-run');

    resumeFailureRunJob($run['job']);

    $after = resumeFailureEventsAfter($run['stream_id'], $run['interrupted_seq']);
    $terminal = collect(iterator_to_array(app(StreamStoreInterface::class)->readFrom($run['stream_id'], 1, 100), false))->last();
    $broadcasts = array_slice($run['broadcaster']->sent, $broadcastsBefore);

    expect(DB::table('agenticgraph_executions')->where('id', $run['execution_id'])->value('status'))->toBe('failed')
        ->and($after)->toBe(['graph_failed', 'execution_terminal'])
        ->and($terminal->payload()['status'])->toBe('failed')
        ->and($terminal->payload()['final_state'])->toBeNull()
        ->and(array_column($broadcasts, 'event'))->toBe(['stream_close'])
        ->and($broadcasts[0]['payload']['status'])->toBe('error')
        ->and($broadcasts[0]['payload']['meta']['final_state'])->toBeNull();

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('adds nothing when the resumed run already handled its own failure', function () {
    $run = resumeFailureInterruptedRun('resume-fail-in-run', failAfterApproval: true);
    $broadcastsBefore = count($run['broadcaster']->sent);

    resumeFailureRunJob($run['job']);

    $after = resumeFailureEventsAfter($run['stream_id'], $run['interrupted_seq']);
    $broadcasts = array_column(array_slice($run['broadcaster']->sent, $broadcastsBefore), 'event');

    expect(DB::table('agenticgraph_executions')->where('id', $run['execution_id'])->value('status'))->toBe('failed')
        ->and(array_slice($after, -3))->toBe(['node_error', 'graph_failed', 'execution_terminal'])
        ->and(array_count_values($after)['execution_terminal'])->toBe(1)
        ->and(end($broadcasts))->toBe('stream_close')
        ->and(array_count_values($broadcasts)['stream_close'])->toBe(1);

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('does nothing more when failed() runs a second time', function () {
    $run = resumeFailureInterruptedRun('resume-fail-twice');
    app(GraphRegistry::class)->unregister('resume-fail-twice');

    resumeFailureRunJob($run['job']);
    $run['job']->failed(new RuntimeException('second failure'));

    expect(resumeFailureEventsAfter($run['stream_id'], $run['interrupted_seq']))->toBe(['graph_failed', 'execution_terminal']);

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('reads tries and timeout from config with defaults when the keys are missing', function () {
    $execution = config('agenticgraph.execution');
    unset($execution['resume_tries'], $execution['resume_timeout']);
    config()->set('agenticgraph.execution', $execution);
    $defaults = new ResumeExecutionJob(1, []);

    config()->set('agenticgraph.execution.resume_tries', '2');
    config()->set('agenticgraph.execution.resume_timeout', '90');
    $configured = new ResumeExecutionJob(1, []);

    expect($defaults->tries)->toBe(1)
        ->and($defaults->timeout)->toBeNull()
        ->and($configured->tries)->toBe(2)
        ->and($configured->timeout)->toBe(90);
});

it('resolves the interrupt as failed so a later resume returns already resolved', function () {
    $run = resumeFailureInterruptedRun('resume-fail-interrupt-state');
    app(GraphRegistry::class)->unregister('resume-fail-interrupt-state');
    Bus::fake();

    resumeFailureRunJob($run['job']);

    $interrupt = InterruptRecord::find($run['job']->interruptId);
    $result = AgenticGraph::resume($run['execution_id'], ['approved' => true]);

    expect($interrupt->status())->toBe('resolved')
        ->and($interrupt->getAttribute('resolution'))->toBe(['outcome' => 'failed'])
        ->and($interrupt->metadata()['resume_error']['type'])->toBe(RuntimeException::class)
        ->and($interrupt->metadata()['resume_error']['message'])->toContain('resume-fail-interrupt-state')
        ->and($result->alreadyResolved)->toBeTrue()
        ->and($result->interrupt?->interruptId())->toBe($run['job']->interruptId);

    Bus::assertNotDispatched(ResumeExecutionJob::class);
});
