<?php

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\InterruptHandlerInterface;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionSkipped;
use Shahzad\AgenticGraph\Events\InterruptExpired;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamResumePayload;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

/**
 * AgenticGraph::skipQueuedRunsUsing(): a queued run the app no longer wants ends as failed with ended_by=skip, with one
 * failed terminal (reason "skipped"), no GraphExecutionFailed, no failed job and no node run.
 */
class SkipRunNode extends PrismNode
{
    public static int $runs = 0;

    public function execute(StateContract $state): StateContract
    {
        self::$runs++;

        return $state->set('done', true);
    }
}

class SkipApprovalNode extends SkipRunNode
{
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
        return $state->set('approved', true);
    }
}

class SkipInterruptHandler implements InterruptHandlerInterface
{
    /** @var list<string> */
    public static array $calls = [];

    public function handle(InterruptRecord $interrupt): InterruptRecord
    {
        return $interrupt;
    }

    public function resolved(InterruptRecord $interrupt, mixed $resolution): void
    {
        self::$calls[] = 'resolved';
    }

    public function failed(InterruptRecord $interrupt, mixed $context): void
    {
        self::$calls[] = 'failed';
    }
}

function skipGraph(string $node = SkipRunNode::class): void
{
    AgenticGraph::register('skip-run', GraphBuilder::create('skip-run')
        ->addNode('work', $node)
        ->setEntryPoint('work')
        ->addExitPoint('work')
        ->build());
}

/**
 * Registers a callback that skips with $result and records what it was asked.
 *
 * @return ArrayObject<int, array{topic: mixed, graph: string, id: string}>
 */
function skipWith(mixed $result = 'brand deleted'): ArrayObject
{
    $asked = new ArrayObject;
    AgenticGraph::skipQueuedRunsUsing(function (StateContract $state, string $graphName, string $executionId) use ($result, $asked) {
        $asked[] = ['topic' => $state->get('topic'), 'graph' => $graphName, 'id' => $executionId];

        return $result;
    });

    return $asked;
}

function skipQueuedJob(StreamingMode $streaming): ExecuteGraphJob
{
    Bus::fake();
    $handle = AgenticGraph::execute('skip-run', new State(['topic' => 'demo']), new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: $streaming, interrupts: true));

    return Bus::dispatched(ExecuteGraphJob::class)->first(fn (ExecuteGraphJob $job) => $job->executionId === $handle->id());
}

/** @return array{0: ResumeExecutionJob, 1: string, 2: int} the job, the execution id, the events before the resume */
function skipResumeJob(StreamingMode $streaming): array
{
    skipGraph(SkipApprovalNode::class);
    $job = skipQueuedJob($streaming);
    app()->call([$job, 'handle']);
    AgenticGraph::getExecution($job->executionId)->resume(['approved' => true]);

    return [Bus::dispatched(ResumeExecutionJob::class)->first(), $job->executionId, count(skipEvents($job->executionId))];
}

function skipContinueJob(string $id, StreamingMode $streaming, ?StreamResumePayload $resume = null): ContinueExecutionJob
{
    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: $streaming);
    $streamId = $streaming === StreamingMode::NONE ? null : 'stream-'.$id;
    $repo = app(ExecutionRepository::class);
    $repo->queued($id, 'skip-run', [], $streamId, $options);
    $repo->started($id, 'skip-run', [], $streamId);

    return new ContinueExecutionJob('skip-run', $id, (new State(['topic' => 'demo']))->toArray(), 'work', executionOptions: $options, streamId: $streamId, streamSeq: 0, resumePayload: $resume);
}

/** @return list<StreamEvent> */
function skipEvents(string $executionId): array
{
    $streamId = DB::table('agenticgraph_executions')->where('id', $executionId)->value('stream_id');

    return $streamId === null ? [] : array_values(iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, 1, 500), false));
}

function skipRow(string $executionId): object
{
    return DB::table('agenticgraph_executions')->where('id', $executionId)->first();
}

/** The skip end state: the row, and on a streamed run the usual failure frames, once, with reason "skipped". */
function expectSkipped(string $executionId, StreamingMode $streaming, int $eventsBefore = 0, string $reason = 'brand deleted'): void
{
    $row = skipRow($executionId);
    $stored = json_decode((string) $row->execution_options, true);

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('Skipped: '.$reason)
        ->and($stored['ended_by'] ?? null)->toBe('skip')
        ->and(AgenticGraph::getExecution($executionId)->isFailed())->toBeTrue()
        ->and(app(ExecutionRepository::class)->reopen($executionId))->toBeFalse()
        ->and(DB::table('agenticgraph_checkpoints')->where('execution_id', $executionId)->where('metadata', 'not like', '%interrupted%')->count())->toBe(0);

    $events = array_slice(skipEvents($executionId), $eventsBefore);
    if ($streaming === StreamingMode::NONE) {
        expect($events)->toBe([]);

        return;
    }

    expect(array_map(fn (StreamEvent $e) => $e->type(), $events))->toBe(['graph_failed', 'execution_terminal'])
        ->and($events[0]->payload()['reason'])->toBe('skipped')
        ->and($events[1]->payload()['status'])->toBe('failed')
        ->and($events[1]->payload()['meta']['reason'])->toBe('skipped')
        ->and(json_encode(array_map(fn (StreamEvent $e) => $e->payload(), $events)))->not->toContain($reason);
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    app()->forgetInstance(StreamStoreInterface::class);
    SkipRunNode::$runs = 0;
    SkipInterruptHandler::$calls = [];
    skipGraph();
});

dataset('skip streaming', ['no stream' => [StreamingMode::NONE], 'sse' => [StreamingMode::SSE]]);

it('ExecuteGraphJob skips the run: failed with ended_by=skip, one terminal, no failure event', function (StreamingMode $streaming) {
    Event::fake([GraphExecutionFailed::class]);
    $job = skipQueuedJob($streaming);
    $asked = skipWith();
    Log::spy();

    app()->call([$job, 'handle']);

    expect(SkipRunNode::$runs)->toBe(0)
        // executeAsync() sends a plain state array, the stream dispatcher a wrapped one: the callback gets a state.
        ->and($asked->getArrayCopy())->toBe([['topic' => 'demo', 'graph' => 'skip-run', 'id' => $job->executionId]]);
    expectSkipped($job->executionId, $streaming);
    Event::assertNotDispatched(GraphExecutionFailed::class);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => $message === 'AgenticGraph: queued run skipped'
        && ($context['execution_id'] ?? null) === $job->executionId && ($context['reason'] ?? null) === 'brand deleted')->once();
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
})->with('skip streaming');

it('never sends the reason to the browser, also with error details exposed', function () {
    config()->set('agenticgraph.streaming.expose_error_details', true);
    $job = skipQueuedJob(StreamingMode::SSE);
    skipWith('tenant acme-42 is suspended');

    app()->call([$job, 'handle']);

    expectSkipped($job->executionId, StreamingMode::SSE, reason: 'tenant acme-42 is suspended');
});

it('broadcasts one failed terminal, then stream_close, with reason skipped', function () {
    $appBroadcaster = frameRecorder();
    $stream = frameRecorder();
    useStreamBroadcaster($appBroadcaster, $stream);
    $job = skipQueuedJob(StreamingMode::BROADCAST);
    skipWith();

    app()->call([$job, 'handle']);

    expect(array_column($stream->frames, 'event'))->toBe(['execution_terminal', 'stream_close'])
        ->and($stream->frames[0]['payload']['payload']['status'])->toBe('failed')
        ->and($stream->frames[0]['payload']['payload']['meta']['reason'])->toBe('skipped')
        ->and($stream->frames[1]['payload']['status'])->toBe('error')
        ->and($stream->frames[1]['payload']['meta']['reason'])->toBe('skipped')
        ->and(json_encode($stream->frames))->not->toContain('brand deleted')
        ->and($appBroadcaster->frames)->toBe([]);
    expectSkipped($job->executionId, StreamingMode::BROADCAST);
});

it('ContinueExecutionJob skips the run', function (StreamingMode $streaming) {
    Event::fake([GraphExecutionFailed::class]);
    $job = skipContinueJob('exec-skip-continue', $streaming);
    $asked = skipWith();

    app()->call([$job, 'handle']);

    expect(SkipRunNode::$runs)->toBe(0)->and($asked)->toHaveCount(1);
    expectSkipped('exec-skip-continue', $streaming);
    Event::assertNotDispatched(GraphExecutionFailed::class);
})->with('skip streaming');

it('ContinueExecutionJob asks before it reopens a failed run for recovery, and the skip is final', function () {
    $resume = new StreamResumePayload(fromSeq: 0, resumedAtSeq: 1, gapEvents: [], checkpointId: 'checkpoint-1');
    $job = skipContinueJob('exec-skip-recovery', StreamingMode::NONE, $resume);
    app(ExecutionRepository::class)->failed('exec-skip-recovery', 'worker died');
    $statusWhenAsked = null;
    AgenticGraph::skipQueuedRunsUsing(function () use (&$statusWhenAsked): string {
        $statusWhenAsked = app(ExecutionRepository::class)->getStatus('exec-skip-recovery');

        return 'brand deleted';
    });

    app()->call([$job, 'handle']);

    expect($statusWhenAsked)->toBe('failed')
        ->and(SkipRunNode::$runs)->toBe(0)
        ->and(skipRow('exec-skip-recovery')->status)->toBe('failed')
        ->and(app(ExecutionRepository::class)->reopen('exec-skip-recovery'))->toBeFalse();
});

it('ResumeExecutionJob skips the run and resolves the interrupt as skipped without calling the handler', function (StreamingMode $streaming) {
    config()->set('agenticgraph.interrupts.handler', SkipInterruptHandler::class);
    [$job, $id, $before] = skipResumeJob($streaming);
    Event::fake([GraphExecutionFailed::class]);
    $asked = skipWith();

    $job->handle(app(InterruptServiceInterface::class), app(StreamResumption::class));

    $interrupt = InterruptRecord::find($job->interruptId);
    expect(SkipRunNode::$runs)->toBe(0)
        ->and($asked->getArrayCopy())->toBe([['topic' => 'demo', 'graph' => 'skip-run', 'id' => $id]])
        ->and($interrupt->status())->toBe('resolved')
        ->and($interrupt->getAttribute('resolution'))->toBe(['outcome' => 'skipped'])
        ->and(SkipInterruptHandler::$calls)->toBe([])
        ->and(AgenticGraph::resume($id, ['approved' => true])->alreadyResolved)->toBeTrue();
    expectSkipped($id, $streaming, $before);
    Event::assertNotDispatched(GraphExecutionFailed::class);
})->with('skip streaming');

it('ExecuteNodeJob skips the whole run, and batch completion is then quiet', function () {
    Bus::fake();
    Event::fake([GraphExecutionFailed::class]);
    $id = 'exec-skip-branch';
    $repo = app(ExecutionRepository::class);
    $repo->queued($id, 'skip-run');
    $repo->started($id, 'skip-run');
    $asked = skipWith();
    $branch = fn () => app()->call([new ExecuteNodeJob($id, 'work', (new State(['topic' => 'demo']))->toArray(), 'skip-run'), 'handle']);

    $branch();
    $branch(); // a sibling branch of an ended run: not asked again, not run

    Log::spy();
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-skip';
    app(ParallelExecutionCoordinator::class)->handleBatchCompletion($batch, 'skip-run', $id, 'work', ['work']);

    expect(SkipRunNode::$runs)->toBe(0)
        ->and($asked)->toHaveCount(1)
        ->and($repo->batchResults($id))->toBe([]);
    expectSkipped($id, StreamingMode::NONE);
    Log::shouldNotHaveReceived('error');
    Bus::assertNotDispatched(ContinueExecutionJob::class);
    Event::assertNotDispatched(GraphExecutionFailed::class);
});

it('later expiry does nothing to a skipped run', function () {
    config()->set('agenticgraph.interrupts.default_expiry_days', 1);
    [$job, $id, $before] = skipResumeJob(StreamingMode::SSE);
    skipWith();
    $job->handle(app(InterruptServiceInterface::class), app(StreamResumption::class));
    Event::fake([GraphExecutionFailed::class, InterruptExpired::class]);

    $this->travel(30)->days();
    Artisan::call('agenticgraph:executions:expire');
    Artisan::call('agenticgraph:interrupt:expire');

    expectSkipped($id, StreamingMode::SSE, $before);
    Event::assertNotDispatched(GraphExecutionFailed::class);
    Event::assertNotDispatched(InterruptExpired::class);
});

it('does not ask about a run that is deleted or already ended', function () {
    $ended = skipQueuedJob(StreamingMode::NONE);
    app(ExecutionRepository::class)->failed($ended->executionId, 'expired');
    $deleted = skipQueuedJob(StreamingMode::NONE);
    DB::table('agenticgraph_executions')->where('id', $deleted->executionId)->delete();
    $asked = skipWith();

    app()->call([$ended, 'handle']);
    app()->call([$deleted, 'handle']);

    expect($asked)->toHaveCount(0)->and(skipRow($ended->executionId)->error)->toBe('expired');
});

it('runs the job when the callback returns null, and after the callback is removed', function () {
    $job = skipQueuedJob(StreamingMode::SSE);
    $asked = skipWith(null);

    app()->call([$job, 'handle']);

    expect($asked)->toHaveCount(1)
        ->and(SkipRunNode::$runs)->toBe(1)
        ->and(AgenticGraph::getExecution($job->executionId)->refresh()->status())->toBe(ExecutionStatus::COMPLETED);

    skipWith();
    AgenticGraph::skipQueuedRunsUsing(null);
    app()->call([skipQueuedJob(StreamingMode::NONE), 'handle']);

    expect(SkipRunNode::$runs)->toBe(2);
});

it('fails the job as usual when the callback throws', function () {
    Event::fake([GraphExecutionFailed::class]);
    $job = skipQueuedJob(StreamingMode::SSE);
    AgenticGraph::skipQueuedRunsUsing(fn () => throw new RuntimeException('brand lookup failed'));

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'brand lookup failed');
    $job->failed(new RuntimeException('brand lookup failed'));

    expect(SkipRunNode::$runs)->toBe(0)
        ->and(skipRow($job->executionId)->status)->toBe('failed')
        ->and(skipRow($job->executionId)->error)->toBe('brand lookup failed');
    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('keeps the usual resume failure path when the callback throws', function () {
    config()->set('agenticgraph.interrupts.handler', SkipInterruptHandler::class);
    [$job] = skipResumeJob(StreamingMode::NONE);
    AgenticGraph::skipQueuedRunsUsing(fn () => throw new RuntimeException('brand lookup failed'));

    expect(fn () => $job->handle(app(InterruptServiceInterface::class), app(StreamResumption::class)))->toThrow(RuntimeException::class);

    expect(InterruptRecord::find($job->interruptId)->status())->toBe('pending')
        ->and(SkipInterruptHandler::$calls)->toBe(['failed']);
});

it('throws on a return value that is not null or a string', function (mixed $value) {
    $job = skipQueuedJob(StreamingMode::NONE);
    skipWith($value);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(UnexpectedValueException::class, 'skipQueuedRunsUsing()')
        ->and(SkipRunNode::$runs)->toBe(0)
        ->and(skipRow($job->executionId)->status)->toBe('queued');
})->with(['false' => [false], 'true' => [true], 'int' => [1]]);

/**
 * Records every GraphExecutionSkipped with the row as the listener sees it: the update has committed by then.
 *
 * @return ArrayObject<int, array{id: string, graph: string, reason: string, status: string, ended_by: ?string, event: object}>
 */
function recordSkips(): ArrayObject
{
    $seen = new ArrayObject;
    Event::listen(GraphExecutionSkipped::class, function (GraphExecutionSkipped $event) use ($seen) {
        $row = skipRow($event->executionId);
        $seen[] = [
            'id' => $event->executionId,
            'graph' => $event->graphName,
            'reason' => $event->reason,
            'status' => $row->status,
            'ended_by' => json_decode((string) $row->execution_options, true)['ended_by'] ?? null,
            'event' => $event,
        ];
    });

    return $seen;
}

function expectSkipEvent(ArrayObject $seen, string $id): void
{
    expect($seen)->toHaveCount(1)
        ->and(array_diff_key($seen[0], ['event' => 1]))->toBe([
            'id' => $id, 'graph' => 'skip-run', 'reason' => 'brand deleted', 'status' => 'failed', 'ended_by' => 'skip',
        ]);
}

it('fires GraphExecutionSkipped once for ExecuteGraphJob, not GraphExecutionFailed', function (StreamingMode $streaming) {
    Event::fake([GraphExecutionFailed::class]);
    $job = skipQueuedJob($streaming);
    skipWith();
    $seen = recordSkips();

    app()->call([$job, 'handle']);

    expectSkipEvent($seen, $job->executionId);
    Event::assertNotDispatched(GraphExecutionFailed::class);
})->with('skip streaming');

it('fires GraphExecutionSkipped once for ContinueExecutionJob', function (StreamingMode $streaming) {
    Event::fake([GraphExecutionFailed::class]);
    $job = skipContinueJob('exec-skip-continue', $streaming);
    skipWith();
    $seen = recordSkips();

    app()->call([$job, 'handle']);

    expectSkipEvent($seen, 'exec-skip-continue');
    Event::assertNotDispatched(GraphExecutionFailed::class);
})->with('skip streaming');

it('fires GraphExecutionSkipped once for ResumeExecutionJob', function (StreamingMode $streaming) {
    [$job, $id] = skipResumeJob($streaming);
    Event::fake([GraphExecutionFailed::class]);
    skipWith();
    $seen = recordSkips();

    $job->handle(app(InterruptServiceInterface::class), app(StreamResumption::class));

    expectSkipEvent($seen, $id);
    Event::assertNotDispatched(GraphExecutionFailed::class);
})->with('skip streaming');

it('fires GraphExecutionSkipped once for a parallel branch, and not again for a sibling or the batch', function () {
    Bus::fake();
    Event::fake([GraphExecutionFailed::class]);
    $repo = app(ExecutionRepository::class);
    $repo->queued('exec-skip-branch', 'skip-run');
    $repo->started('exec-skip-branch', 'skip-run');
    skipWith();
    $seen = recordSkips();
    $branch = fn () => app()->call([new ExecuteNodeJob('exec-skip-branch', 'work', (new State(['topic' => 'demo']))->toArray(), 'skip-run'), 'handle']);

    $branch();
    $branch();
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-skip';
    app(ParallelExecutionCoordinator::class)->handleBatchCompletion($batch, 'skip-run', 'exec-skip-branch', 'work', ['work']);

    expectSkipEvent($seen, 'exec-skip-branch');
    Event::assertNotDispatched(GraphExecutionFailed::class);
});

it('fires GraphExecutionSkipped once when two parallel branches skip at the same time, from the call that changed the row', function () {
    Bus::fake();
    $repo = app(ExecutionRepository::class);
    $repo->queued('exec-skip-race', 'skip-run');
    $repo->started('exec-skip-race', 'skip-run');
    $branch = fn () => app()->call([new ExecuteNodeJob('exec-skip-race', 'work', (new State(['topic' => 'demo']))->toArray(), 'skip-run'), 'handle']);
    $asked = 0;
    $callers = [];
    // The first branch is inside its callback when the second branch starts: both passed the "has ended" check.
    AgenticGraph::skipQueuedRunsUsing(function () use (&$asked, &$callers, $branch) {
        $asked++;
        $me = $asked;
        if ($me === 1) {
            $branch();
        }
        $callers[] = $me;

        return 'brand deleted';
    });
    $seen = recordSkips();
    $callersWhenFired = null;
    Event::listen(GraphExecutionSkipped::class, function () use (&$callers, &$callersWhenFired) {
        $callersWhenFired = $callers;
    });

    $branch();

    expectSkipEvent($seen, 'exec-skip-race');
    expect($asked)->toBe(2)
        ->and($callersWhenFired)->toBe([2]); // Fired while the first branch was still in its callback: by the second.
    expectSkipped('exec-skip-race', StreamingMode::NONE);
});

it('fires no GraphExecutionSkipped when the skip does not change the row', function (string $ending) {
    $job = skipQueuedJob(StreamingMode::SSE);
    AgenticGraph::skipQueuedRunsUsing(function (StateContract $state, string $graph, string $id) use ($ending) {
        // The run ends between the "has ended" check and the skip.
        $ending === 'failed'
            ? app(ExecutionRepository::class)->failed($id, 'worker died')
            : DB::table('agenticgraph_executions')->where('id', $id)->update(['status' => 'completed']);

        return 'brand deleted';
    });
    $seen = recordSkips();

    app()->call([$job, 'handle']);

    expect($seen)->toHaveCount(0)
        ->and(skipRow($job->executionId)->status)->toBe($ending)
        ->and(SkipRunNode::$runs)->toBe(0);
})->with(['already failed' => ['failed'], 'already completed' => ['completed']]);

it('fires no GraphExecutionSkipped for a run that is deleted or already ended', function () {
    $ended = skipQueuedJob(StreamingMode::NONE);
    app(ExecutionRepository::class)->failed($ended->executionId, 'expired');
    $deleted = skipQueuedJob(StreamingMode::NONE);
    DB::table('agenticgraph_executions')->where('id', $deleted->executionId)->delete();
    skipWith();
    $seen = recordSkips();

    app()->call([$ended, 'handle']);
    app()->call([$deleted, 'handle']);

    expect($seen)->toHaveCount(0);
});

it('does not broadcast GraphExecutionSkipped, and the reason never reaches the stream or the browser', function () {
    config()->set('agenticgraph.streaming.expose_error_details', true);
    $appBroadcaster = frameRecorder();
    $stream = frameRecorder();
    useStreamBroadcaster($appBroadcaster, $stream);
    $job = skipQueuedJob(StreamingMode::BROADCAST);
    skipWith('tenant acme-42 is suspended');
    $seen = recordSkips();

    app()->call([$job, 'handle']);

    expect($seen)->toHaveCount(1)
        ->and($seen[0]['reason'])->toBe('tenant acme-42 is suspended')
        ->and($seen[0]['event'])->not->toBeInstanceOf(ShouldBroadcast::class)
        ->and($appBroadcaster->frames)->toBe([])
        ->and(json_encode($stream->frames))->not->toContain('acme-42')
        ->and(json_encode(array_map(fn (StreamEvent $e) => $e->payload(), skipEvents($job->executionId))))->not->toContain('acme-42');
    Bus::assertNotDispatched(BroadcastEvent::class);
});

it('lets a listener failure out of the job after the run is skipped, and fires neither event again', function () {
    $failedFired = new ArrayObject;
    Event::listen(GraphExecutionFailed::class, fn () => $failedFired[] = true);
    $job = skipQueuedJob(StreamingMode::SSE);
    skipWith();
    Event::listen(GraphExecutionSkipped::class, fn () => throw new RuntimeException('listener broke'));

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'listener broke');
    $job->failed(new RuntimeException('listener broke'));
    app()->call([$job, 'handle']); // a retry: the run has ended

    expectSkipped($job->executionId, StreamingMode::SSE);
    expect($failedFired)->toHaveCount(0);
});

it('fires no GraphExecutionSkipped when a recovery job marks an already failed run as skipped', function () {
    $resume = new StreamResumePayload(fromSeq: 0, resumedAtSeq: 1, gapEvents: [], checkpointId: 'checkpoint-1');
    $job = skipContinueJob('exec-skip-recovery-event', StreamingMode::NONE, $resume);
    app(ExecutionRepository::class)->failed('exec-skip-recovery-event', 'worker died');
    skipWith();
    $seen = recordSkips();

    app()->call([$job, 'handle']);

    expect($seen)->toHaveCount(0)
        ->and(app(ExecutionRepository::class)->endedForGood('exec-skip-recovery-event'))->toBeTrue();
});

it('keeps a skipped resume skipped when the GraphExecutionSkipped listener throws, and still fails the job', function () {
    config()->set('agenticgraph.interrupts.handler', SkipInterruptHandler::class);
    [$job, $id] = skipResumeJob(StreamingMode::SSE);
    skipWith();
    $fired = new ArrayObject;
    Event::listen(GraphExecutionSkipped::class, function () use ($fired, $job): void {
        $fired[] = InterruptRecord::find($job->interruptId)->status();
        throw new RuntimeException('listener broke');
    });

    expect(fn () => $job->handle(app(InterruptServiceInterface::class), app(StreamResumption::class)))->toThrow(RuntimeException::class, 'listener broke');
    $job->failed(new RuntimeException('listener broke'));

    $interrupt = InterruptRecord::find($job->interruptId);
    expect($fired->getArrayCopy())->toBe(['resolved']) // once, with the end already stored
        ->and(skipRow($id)->status)->toBe('failed')
        ->and($interrupt->status())->toBe('resolved')
        ->and($interrupt->getAttribute('resolution'))->toBe(['outcome' => 'skipped'])
        ->and(SkipInterruptHandler::$calls)->toBe([]);
});
