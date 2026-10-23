<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\InterruptExpired;
use Shahzad\AgenticGraph\Exceptions\InterruptExpiredException;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
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
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

class ExpiryApprovalNode extends PrismNode
{
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

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $this->handleApproval($state, $humanInput['approved'] ?? false, null);
    }
}

class ExpiryBroadcaster implements Broadcaster
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

function expiryGraph(string $name): void
{
    AgenticGraph::register($name, GraphBuilder::create($name)
        ->addNode('approve', ExpiryApprovalNode::class)
        ->setEntryPoint('approve')
        ->addExitPoint('approve')
        ->build());
}

/**
 * Runs an async broadcast graph to its approval interrupt, as the refund demo does.
 *
 * @return array{execution_id: string, stream_id: string, broadcaster: ExpiryBroadcaster, interrupted_seq: int, interrupt: InterruptRecord}
 */
function expiryInterruptedAsyncRun(string $name): array
{
    expiryGraph($name);
    $broadcaster = new ExpiryBroadcaster;
    app()->instance(Broadcaster::class, $broadcaster);
    Bus::fake();

    $handle = AgenticGraph::execute($name, new State, new ExecutionOptions(
        mode: ExecutionMode::ASYNC,
        streaming: StreamingMode::BROADCAST,
        interrupts: true,
    ));
    Bus::dispatched(ExecuteGraphJob::class)
        ->first(fn (ExecuteGraphJob $job) => $job->executionId === $handle->id())
        ->handle(app(GraphExecutor::class), app(GraphRegistry::class));

    return [
        'execution_id' => $handle->id(),
        'stream_id' => (string) $handle->streamId(),
        'broadcaster' => $broadcaster,
        'interrupted_seq' => (int) app(StreamStoreInterface::class)->lastSeq((string) $handle->streamId()),
        'interrupt' => InterruptRecord::query()->forExecution($handle->id())->latest('id')->firstOrFail(),
    ];
}

/**
 * @return list<StreamEvent>
 */
function expiryEventsAfter(string $streamId, int $seq): array
{
    return array_values(iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, $seq + 1, 100), false));
}

function expiryStatus(string $executionId): ?string
{
    return DB::table('agenticgraph_executions')->where('id', $executionId)->value('status');
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    app()->forgetInstance(StreamStoreInterface::class);
    Event::fake([GraphExecutionFailed::class, InterruptExpired::class]);
});

it('expires a pending interrupt past expires_at and ends its execution and stream', function () {
    $run = expiryInterruptedAsyncRun('expiry-ends-run');
    $run['interrupt']->update(['expires_at' => now()->subMinute()]);
    $broadcastsBefore = count($run['broadcaster']->sent);

    $this->artisan('agenticgraph:interrupt:expire')->expectsOutput('Expired 1 interrupt(s).')->assertSuccessful();

    $after = expiryEventsAfter($run['stream_id'], $run['interrupted_seq']);
    $broadcasts = array_slice($run['broadcaster']->sent, $broadcastsBefore);

    expect($run['interrupt']->refresh()->status())->toBe('expired')
        ->and(expiryStatus($run['execution_id']))->toBe('failed')
        ->and(array_map(fn (StreamEvent $event) => $event->type(), $after))->toBe(['graph_failed', 'execution_terminal'])
        ->and($after[0]->payload()['reason'])->toBe('interrupt_expired')
        ->and($after[0]->payload()['error_type'])->toBe(InterruptExpiredException::class)
        ->and($after[1]->payload()['status'])->toBe('failed')
        ->and(array_column($broadcasts, 'event'))->toBe(['stream_close'])
        ->and($broadcasts[0]['payload']['status'])->toBe('error');

    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
    Event::assertDispatched(InterruptExpired::class, fn (InterruptExpired $event) => $event->interruptId === $run['interrupt']->interruptId()
        && $event->executionId === $run['execution_id']
        && $event->checkpointId === $run['interrupt']->checkpointId());
    Event::assertDispatchedTimes(InterruptExpired::class, 1);
});

it('leaves interrupts that are not due, already claimed, or without expiry, and does nothing on a second run', function () {
    $due = expiryInterruptedAsyncRun('expiry-due')['interrupt'];
    $notDue = expiryInterruptedAsyncRun('expiry-not-due')['interrupt'];
    $resolving = expiryInterruptedAsyncRun('expiry-resolving')['interrupt'];
    $noExpiry = expiryInterruptedAsyncRun('expiry-none')['interrupt'];
    $due->update(['expires_at' => now()->subMinute()]);
    $notDue->update(['expires_at' => now()->addMinute()]);
    $resolving->update(['expires_at' => now()->subMinute(), 'status' => 'resolving']);
    $noExpiry->update(['expires_at' => null]);

    Artisan::call('agenticgraph:interrupt:expire');
    Artisan::call('agenticgraph:interrupt:expire');

    expect([$due->refresh()->status(), $notDue->refresh()->status(), $resolving->refresh()->status(), $noExpiry->refresh()->status()])
        ->toBe(['expired', 'pending', 'resolving', 'pending'])
        ->and(trim(Artisan::output()))->toBe('Expired 0 interrupt(s).');
    Event::assertDispatchedTimes(InterruptExpired::class, 1);
    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('refuses an expired interrupt in AgenticGraph::resume and dispatches nothing', function () {
    $run = expiryInterruptedAsyncRun('expiry-resume-facade');
    $run['interrupt']->update(['expires_at' => now()->subMinute()]);
    Artisan::call('agenticgraph:interrupt:expire');
    Bus::fake();

    $result = AgenticGraph::resume($run['execution_id'], ['approved' => true]);

    expect($result->expired)->toBeTrue()
        ->and($result->alreadyResolved)->toBeFalse()
        ->and($result->interrupt?->interruptId())->toBe($run['interrupt']->interruptId())
        ->and(expiryStatus($run['execution_id']))->toBe('failed');
    Bus::assertNotDispatched(ResumeExecutionJob::class);
});

it('refuses an expired interrupt in the async ExecutionHandle::resume', function () {
    $run = expiryInterruptedAsyncRun('expiry-resume-async');
    $handle = AgenticGraph::getExecution($run['execution_id'])->refresh();
    $run['interrupt']->update(['status' => 'expired']);
    Bus::fake();

    expect(fn () => $handle->resume(['approved' => true]))->toThrow(InterruptExpiredException::class);
    Bus::assertNotDispatched(ResumeExecutionJob::class);
});

it('refuses resume on a fresh handle after the command ended the execution', function () {
    $run = expiryInterruptedAsyncRun('expiry-resume-after-command');
    $run['interrupt']->update(['expires_at' => now()->subMinute()]);
    Artisan::call('agenticgraph:interrupt:expire');
    Bus::fake();

    // The failed execution's state no longer names the interrupt checkpoint.
    expect(fn () => AgenticGraph::getExecution($run['execution_id'])->resume(['approved' => true]))
        ->toThrow(InterruptExpiredException::class);
    Bus::assertNotDispatched(ResumeExecutionJob::class);
});

it('refuses an expired interrupt in the sync ExecutionHandle::resume', function () {
    expiryGraph('expiry-resume-sync');
    $handle = AgenticGraph::execute('expiry-resume-sync', new State, new ExecutionOptions(interrupts: true));
    InterruptRecord::query()->forExecution($handle->id())->update(['status' => 'expired']);

    expect(fn () => $handle->resume(['approved' => true]))->toThrow(InterruptExpiredException::class)
        ->and(expiryStatus($handle->id()))->toBe('interrupted');
});

it('refuses an expired interrupt in agenticgraph:checkpoint:resume and in a stream resume', function () {
    $run = expiryInterruptedAsyncRun('expiry-resume-checkpoint');
    $run['interrupt']->update(['status' => 'expired']);
    $checkpointId = (string) $run['interrupt']->checkpointId();

    $this->artisan('agenticgraph:checkpoint:resume', ['id' => $checkpointId])
        ->expectsOutputToContain('expired')
        ->assertFailed();
    expect(fn () => app(StreamResumption::class)->resumeStream($checkpointId, ['approved' => true], new StreamOptions))
        ->toThrow(InterruptExpiredException::class);
});

it('does not let a resume claim an interrupt the expire command claimed first', function () {
    $interrupt = expiryInterruptedAsyncRun('expiry-claim')['interrupt'];
    $interrupt->update(['status' => 'expired']);

    expect($interrupt->markResolving())->toBeFalse()
        ->and($interrupt->refresh()->status())->toBe('expired');
});

it('expires every due interrupt when the broadcast fails, and logs the failure', function () {
    $first = expiryInterruptedAsyncRun('expiry-broadcast-down-1');
    $second = expiryInterruptedAsyncRun('expiry-broadcast-down-2');
    $first['interrupt']->update(['expires_at' => now()->subMinute()]);
    $second['interrupt']->update(['expires_at' => now()->subMinute()]);
    app()->instance(Broadcaster::class, new class extends ExpiryBroadcaster
    {
        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new RuntimeException('Reverb down');
        }
    });
    Log::spy();

    $this->artisan('agenticgraph:interrupt:expire')->expectsOutput('Expired 2 interrupt(s).')->assertSuccessful();

    foreach ([$first, $second] as $run) {
        expect($run['interrupt']->refresh()->status())->toBe('expired')
            ->and(expiryStatus($run['execution_id']))->toBe('failed')
            ->and(array_map(fn (StreamEvent $event) => $event->type(), expiryEventsAfter($run['stream_id'], $run['interrupted_seq'])))
            ->toBe(['graph_failed', 'execution_terminal']);
    }
    Event::assertDispatchedTimes(GraphExecutionFailed::class, 2);
    Event::assertDispatchedTimes(InterruptExpired::class, 2);
    Log::shouldHaveReceived('warning')->with(Mockery::any(), Mockery::on(fn (array $context) => ($context['message'] ?? null) === 'Reverb down'))->twice();
});

it('expires each interrupt completely or not at all, independently of the others', function () {
    $broken = expiryInterruptedAsyncRun('expiry-atomic-broken');
    $healthy = expiryInterruptedAsyncRun('expiry-atomic-healthy');
    $broken['interrupt']->update(['expires_at' => now()->subMinute()]);
    $healthy['interrupt']->update(['expires_at' => now()->subMinute()]);
    // Fails after the execution row is written, so a partial expiry would leave it failed.
    app()->instance(ExecutionRepository::class, new class($broken['execution_id']) extends ExecutionRepository
    {
        public function __construct(private readonly string $brokenId)
        {
            parent::__construct();
        }

        public function failed(string $id, string $error): bool
        {
            $changed = parent::failed($id, $error);
            if ($id === $this->brokenId) {
                throw new RuntimeException('database went away');
            }

            return $changed;
        }
    });

    $this->artisan('agenticgraph:interrupt:expire')->expectsOutput('Expired 1 interrupt(s).')->assertSuccessful();

    expect($broken['interrupt']->refresh()->status())->toBe('pending')
        ->and(expiryStatus($broken['execution_id']))->toBe('interrupted')
        ->and(expiryEventsAfter($broken['stream_id'], $broken['interrupted_seq']))->toBe([])
        ->and($healthy['interrupt']->refresh()->status())->toBe('expired')
        ->and(expiryStatus($healthy['execution_id']))->toBe('failed');
    Event::assertDispatchedTimes(InterruptExpired::class, 1);
    Event::assertDispatched(InterruptExpired::class, fn (InterruptExpired $event) => $event->executionId === $healthy['execution_id']);
});
