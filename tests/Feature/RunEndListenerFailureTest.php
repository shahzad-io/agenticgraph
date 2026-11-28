<?php

use Illuminate\Bus\Batch;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Shahzad\AgenticGraph\Contracts\InterruptHandlerInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionSkipped;
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\InterruptExpired;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumePayload;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceContextFactory;

/*
 * Core 0.4.3: a run-ending event listener that throws. The queued cases run on the database queue with queue:work, so
 * "the job is recorded as failed" is what a real worker records.
 */

class RELNode extends AbstractNode
{
    public function getRetryAttempts(): int
    {
        return 1;
    }

    public function execute(StateContract $state): StateContract
    {
        if ($state->get('boom')) {
            throw new RuntimeException('node broke');
        }

        return $state->set('done', true);
    }
}

class RELApprovalNode extends PrismNode
{
    public function getRetryAttempts(): int
    {
        return 1;
    }

    public function execute(StateContract $state): StateContract
    {
        if ($state->get('fail_after_approval') && $state->has('approved')) {
            throw new RuntimeException('node broke');
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

/** Records the graph and the loop counts its run sees. */
class RELProbeNode extends AbstractNode
{
    /** @var array<string, mixed> */
    public static array $seen = [];

    public function execute(StateContract $state): StateContract
    {
        $context = app(ExecutionContext::class);
        self::$seen = ['graph' => app(Graph::class)->name, 'visits' => $context->nodeVisitCounts, 'iterations' => $context->globalIterations];

        return $state;
    }
}

class RELHandler implements InterruptHandlerInterface
{
    /** @var list<array{0: string, 1: mixed}> */
    public static array $calls = [];

    public static bool $resolvedThrows = false;

    public function handle(InterruptRecord $interrupt): InterruptRecord
    {
        return $interrupt;
    }

    public function resolved(InterruptRecord $interrupt, mixed $humanInput): void
    {
        self::$calls[] = ['resolved', $humanInput];
        if (self::$resolvedThrows) {
            throw new RuntimeException('handler broke');
        }
    }

    public function failed(InterruptRecord $interrupt, mixed $error): void
    {
        self::$calls[] = ['failed', $error];
    }
}

/** The configured stream store, with the append of one event type failing. */
class RELBrokenStore implements StreamStoreInterface
{
    public function __construct(private StreamStoreInterface $inner, public ?string $failOn) {}

    public function append(StreamEvent $event): void
    {
        if ($event->type() === $this->failOn) {
            throw new RuntimeException('store down at '.$this->failOn);
        }
        $this->inner->append($event);
    }

    public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
    {
        return $this->inner->readFrom($streamId, $fromSeq, $limit);
    }

    public function lastSeq(string $streamId): ?int
    {
        return $this->inner->lastSeq($streamId);
    }

    public function firstSeq(string $streamId): ?int
    {
        return $this->inner->firstSeq($streamId);
    }

    public function truncate(string $streamId, int $beforeSeq): void
    {
        $this->inner->truncate($streamId, $beforeSeq);
    }
}

class RELBroadcaster implements Broadcaster
{
    public function auth($request) {}

    public function validAuthenticationResponse($request, $result) {}

    public function broadcast(array $channels, $event, array $payload = []) {}
}

class RELTraceExporter extends PulseExporter
{
    protected function send(array $payload): void {}
}

class RELFork implements RouterInterface
{
    public function route(StateContract $state): string|array
    {
        return ['a', 'b'];
    }

    public function getAvailableRoutes(): array
    {
        return ['a', 'b'];
    }

    public function getDefaultRoute(): ?string
    {
        return null;
    }

    public function validateRoute(string $route): bool
    {
        return in_array($route, ['a', 'b'], true);
    }
}

function relGraph(string $name, string $node = RELNode::class): void
{
    AgenticGraph::register($name, GraphBuilder::create($name)
        ->addNode('step', $node)
        ->setEntryPoint('step')
        ->addExitPoint('step')
        ->build());
}

function relBreakStore(string $type): RELBrokenStore
{
    config()->set('agenticgraph.streaming.errors.store_fatal', true);
    app()->forgetInstance(StreamStoreInterface::class);
    $store = new RELBrokenStore(app(StreamStoreInterface::class), $type);
    app()->instance(StreamStoreInterface::class, $store);

    return $store;
}

/** @return list<string> the scope bindings still in the container */
function relBound(): array
{
    return array_values(array_filter(
        [TraceContext::class, ExecutionClock::class, ExecutionContext::class, LoopMetadata::class, Graph::class, StreamContext::class],
        fn (string $abstract) => app()->bound($abstract),
    ));
}

/** A second run in this process, of a different graph: it must see its own graph and fresh loop counts. */
function relExpectCleanSecondRun(): void
{
    relGraph('rel-probe', RELProbeNode::class);
    RELProbeNode::$seen = [];
    AgenticGraph::execute('rel-probe', new State, new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE));

    expect(RELProbeNode::$seen)->toBe(['graph' => 'rel-probe', 'visits' => ['step' => 1], 'iterations' => 1]);
}

function relOptions(StreamingMode $streaming = StreamingMode::NONE, ExecutionMode $mode = ExecutionMode::ASYNC): ExecutionOptions
{
    return new ExecutionOptions(mode: $mode, streaming: $streaming, interrupts: true);
}

/** @return ArrayObject<int, array{0: string, 1: string}> the failed jobs, as the worker records them */
function relFailedJobs(): ArrayObject
{
    $failed = new ArrayObject;
    Event::listen(JobFailed::class, fn (JobFailed $e) => $failed[] = [class_basename($e->job->resolveName()), $e->exception->getMessage()]);

    return $failed;
}

function relWork(int $tries = 1): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'agenticgraph', '--stop-when-empty' => true, '--tries' => $tries, '--sleep' => 0, '--memory' => 2048]);
}

/** @return ArrayObject<int, array<string, mixed>> the context of each "run-ending event listener failed" line */
function relListenerLines(): ArrayObject
{
    $lines = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $e) use ($lines): void {
        if ($e->message === 'AgenticGraph: run-ending event listener failed') {
            $lines[] = ['level' => $e->level] + $e->context;
        }
    });

    return $lines;
}

/** @return ArrayObject<int, string> */
function relThrowingListener(string $event, ?Closure $error = null): ArrayObject
{
    $seen = new ArrayObject;
    Event::listen($event, function (object $e) use ($seen, $error): void {
        $seen[] = $e->executionId;
        throw $error !== null ? $error() : new RuntimeException('listener broke');
    });

    return $seen;
}

function relRow(string $executionId): object
{
    return DB::table('agenticgraph_executions')->where('id', $executionId)->first();
}

/** @return list<string> */
function relTypes(string $streamId): array
{
    return array_map(fn (StreamEvent $e) => $e->type(), iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, 1, 200), false));
}

/** A queued run on the database queue that reached its approval interrupt and was approved: the resume job waits. */
function relQueuedResume(StreamingMode $streaming, array $state = []): array
{
    relGraph('rel-approval', RELApprovalNode::class);
    $handle = AgenticGraph::execute('rel-approval', new State($state), relOptions($streaming));
    relWork();
    $handle->refresh()->resume(['approved' => true]);

    return [
        'id' => $handle->id(),
        'stream_id' => (string) relRow($handle->id())->stream_id,
        'interrupt' => InterruptRecord::query()->forExecution($handle->id())->latest('id')->firstOrFail(),
    ];
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.interrupts.handler', RELHandler::class);
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.execution.queue_connection', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    config()->set('queue.failed.driver', 'null');
    Schema::create('jobs', function (Blueprint $t) {
        $t->id();
        $t->string('queue')->index();
        $t->longText('payload');
        $t->unsignedTinyInteger('attempts');
        $t->unsignedInteger('reserved_at')->nullable();
        $t->unsignedInteger('available_at');
        $t->unsignedInteger('created_at');
    });
    app()->instance(Broadcaster::class, new RELBroadcaster);
    app()->forgetInstance(StreamStoreInterface::class);
    RELHandler::$calls = [];
    RELHandler::$resolvedThrows = false;
});

// Item 1: a completion listener throws.

it('fails a queued job whose completion listener throws, and keeps the run completed', function (StreamingMode $streaming, int $tries) {
    config()->set('agenticgraph.execution.tries', $tries);
    $failed = relFailedJobs();
    $lines = relListenerLines();
    $seen = relThrowingListener(GraphExecutionCompleted::class);
    relGraph('rel-complete');

    $handle = AgenticGraph::execute('rel-complete', new State, relOptions($streaming));
    relWork($tries);

    expect($failed->getArrayCopy())->toBe([['ExecuteGraphJob', 'listener broke']])
        ->and($seen->getArrayCopy())->toBe([$handle->id()])
        ->and(relRow($handle->id())->status)->toBe('completed')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(count($lines))->toBe(1)
        ->and($lines[0]['execution_id'])->toBe($handle->id())
        ->and(relBound())->toBe([]);
    if ($streaming !== StreamingMode::NONE) {
        expect(array_slice(relTypes((string) relRow($handle->id())->stream_id), -2))->toBe(['graph_complete', 'execution_terminal']);
    }
})->with(['non-streaming' => StreamingMode::NONE, 'streaming' => StreamingMode::SSE])->with(['tries 1' => 1, 'tries 3' => 3]);

it('resolves the interrupt with the input before the completion listener of a queued resume runs', function (StreamingMode $streaming) {
    config()->set('agenticgraph.execution.resume_tries', 3);
    $failed = relFailedJobs();
    $run = relQueuedResume($streaming);
    $atListener = null;
    Event::listen(GraphExecutionCompleted::class, function () use (&$atListener, $run): void {
        $fresh = $run['interrupt']->fresh();
        $atListener = [$fresh->status(), $fresh->getAttribute('resolution')];
        throw new RuntimeException('listener broke');
    });

    relWork(3);

    $interrupt = $run['interrupt']->fresh();
    $input = ['input' => ['approved' => true]];
    expect($atListener)->toBe(['resolved', $input])
        ->and([$interrupt->status(), $interrupt->getAttribute('resolution')])->toBe(['resolved', $input])
        ->and(RELHandler::$calls)->toBe([['resolved', $input]])
        ->and($failed->getArrayCopy())->toBe([['ResumeExecutionJob', 'listener broke']])
        ->and(relRow($run['id'])->status)->toBe('completed');
})->with(['non-streaming' => StreamingMode::NONE, 'streaming' => StreamingMode::SSE]);

it('resolves the interrupt of a sync resume and gives the caller the listener error', function (StreamingMode $streaming) {
    relGraph('rel-approval', RELApprovalNode::class);
    $handle = AgenticGraph::execute('rel-approval', new State, relOptions($streaming, ExecutionMode::SYNC));
    $interrupt = InterruptRecord::query()->forExecution($handle->id())->latest('id')->firstOrFail();
    $atListener = null;
    Event::listen(GraphExecutionCompleted::class, function () use (&$atListener, $interrupt): void {
        $atListener = $interrupt->fresh()->status();
        throw new RuntimeException('listener broke');
    });

    expect(fn () => $handle->resume(['approved' => true]))->toThrow(RuntimeException::class, 'listener broke');

    expect($atListener)->toBe('resolved')
        ->and($interrupt->fresh()->getAttribute('resolution'))->toBe(['input' => ['approved' => true]])
        ->and(relRow($handle->id())->status)->toBe('completed')
        ->and(relBound())->toBe([]);
})->with(['non-streaming' => StreamingMode::NONE, 'streaming' => StreamingMode::ITERATOR]);

it('still fires one completion event, and fails the job, when a completion frame fails', function (string $frame) {
    config()->set('agenticgraph.execution.tries', 3);
    $failed = relFailedJobs();
    $seen = new ArrayObject;
    Event::listen(GraphExecutionCompleted::class, fn (GraphExecutionCompleted $e) => $seen[] = $e->executionId);
    relBreakStore($frame);
    relGraph('rel-frame');

    $handle = AgenticGraph::execute('rel-frame', new State, relOptions(StreamingMode::SSE));
    relWork(3);

    expect($seen->getArrayCopy())->toBe([$handle->id()])
        ->and($failed->getArrayCopy())->toBe([['ExecuteGraphJob', 'store down at '.$frame]])
        ->and(relRow($handle->id())->status)->toBe('completed');
    // The other frame is still written.
    expect(relTypes((string) relRow($handle->id())->stream_id))
        ->toContain($frame === 'graph_complete' ? 'execution_terminal' : 'graph_complete');
})->with(['graph_complete', 'execution_terminal']);

it('does not reopen the interrupt when the handler resolved() throws', function () {
    config()->set('agenticgraph.execution.resume_tries', 3);
    $failed = relFailedJobs();
    $run = relQueuedResume(StreamingMode::NONE);
    RELHandler::$resolvedThrows = true;

    relWork(3);

    $interrupt = $run['interrupt']->fresh();
    expect([$interrupt->status(), $interrupt->getAttribute('resolution')])->toBe(['resolved', ['input' => ['approved' => true]]])
        ->and(RELHandler::$calls)->toBe([['resolved', ['input' => ['approved' => true]]]])
        ->and($failed->getArrayCopy())->toBe([['ResumeExecutionJob', 'handler broke']])
        ->and(relRow($run['id'])->status)->toBe('completed');
});

it('adds no failed frames in failed() on a completed run, and does not resolve its interrupt as failed', function (string $interruptStatus) {
    $run = relQueuedResume(StreamingMode::SSE);
    $job = new ResumeExecutionJob($run['interrupt']->interruptId(), ['approved' => true]);
    relWork();
    DB::table('stream_events')->where('stream_id', $run['stream_id'])
        ->where('seq', app(StreamStoreInterface::class)->lastSeq($run['stream_id']))->delete(); // the terminal never arrived
    if ($interruptStatus === 'resolving') {
        $run['interrupt']->fresh()->update(['status' => 'resolving', 'resolution' => null]); // killed before the resolve
    }
    $before = relTypes($run['stream_id']);

    $job->failed(new RuntimeException('timed out in a completion listener'));

    $interrupt = $run['interrupt']->fresh();
    expect(relTypes($run['stream_id']))->toBe($before)
        ->and($interrupt->status())->toBe($interruptStatus)
        ->and($interrupt->getAttribute('resolution'))->toBe($interruptStatus === 'resolved' ? ['input' => ['approved' => true]] : null)
        ->and($interrupt->metadata())->not->toHaveKey('resume_error');
})->with(['resolved', 'resolving']);

// Item 2: a failure listener throws.

it('gives the caller the original error when a failure listener throws, and removes every binding', function (string $path) {
    Exceptions::fake();
    $lines = relListenerLines();
    $seen = relThrowingListener(GraphExecutionFailed::class);
    relGraph('rel-fail');
    $graph = app(GraphRegistry::class)->get('rel-fail');
    $expected = 'node broke';
    $run = match ($path) {
        'non-streaming' => fn () => AgenticGraph::execute('rel-fail', new State(['boom' => true]), relOptions(StreamingMode::NONE, ExecutionMode::SYNC)),
        'streaming' => fn () => AgenticGraph::execute('rel-fail', new State(['boom' => true]), relOptions(StreamingMode::ITERATOR, ExecutionMode::SYNC)),
        'from node' => fn () => app(GraphExecutor::class)->executeFromNode($graph, new State(['boom' => true]), 'step', 'exec_rel_node', loopMetadata: new LoopMetadata(1, ['step'], ['step' => 1], [], ['step' => [0]], microtime(true))),
        'streaming setup' => fn () => AgenticGraph::execute('rel-fail', new State, relOptions(StreamingMode::ITERATOR, ExecutionMode::SYNC)),
        'streaming resume setup' => fn () => app(GraphExecutor::class)->executeWithStreamingFromNode($graph, new State, 'step', 'exec_rel_setup', new StreamOptions(delivery: 'iterator'), null, new StreamResumePayload(fromSeq: 0, resumedAtSeq: 1, gapEvents: [], checkpointId: 'cp')),
    };
    if (in_array($path, ['from node', 'streaming resume setup'], true)) {
        $id = $path === 'from node' ? 'exec_rel_node' : 'exec_rel_setup';
        app(ExecutionRepository::class)->queued($id, 'rel-fail');
        app(ExecutionRepository::class)->started($id, 'rel-fail');
    }
    if (str_contains($path, 'setup')) {
        $frame = $path === 'streaming setup' ? 'graph_start' : 'stream_resume';
        relBreakStore($frame);
        $expected = 'store down at '.$frame;
    }

    expect($run)->toThrow(RuntimeException::class, $expected);

    expect(count($seen))->toBe(1)
        ->and(count($lines))->toBe(1)
        ->and(relBound())->toBe([]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'listener broke');
    relExpectCleanSecondRun();
})->with(['non-streaming', 'streaming', 'from node', 'streaming setup', 'streaming resume setup']);

it('gives a second run its own graph and fresh loop counts after a completion listener threw', function (StreamingMode $streaming) {
    $broken = true;
    Event::listen(GraphExecutionCompleted::class, function () use (&$broken): void {
        if ($broken) {
            $broken = false;
            throw new RuntimeException('listener broke');
        }
    });
    relGraph('rel-fail');

    expect(fn () => AgenticGraph::execute('rel-fail', new State, relOptions($streaming, ExecutionMode::SYNC)))
        ->toThrow(RuntimeException::class, 'listener broke');

    expect(relBound())->toBe([]);
    relExpectCleanSecondRun();
})->with(['non-streaming' => StreamingMode::NONE, 'streaming' => StreamingMode::ITERATOR]);

it('removes the scope bindings when a start listener throws in a non-streaming sync run', function () {
    $broken = true;
    Event::listen(GraphExecutionStarted::class, function () use (&$broken): void {
        if ($broken) {
            $broken = false;
            throw new RuntimeException('listener broke');
        }
    });
    relGraph('rel-fail');

    expect(fn () => AgenticGraph::execute('rel-fail', new State, relOptions(StreamingMode::NONE, ExecutionMode::SYNC)))
        ->toThrow(RuntimeException::class, 'listener broke');

    relExpectCleanSecondRun();
    expect(relBound())->toBe([]);
});

it('keeps the bindings the caller had when a start listener throws in a sync run', function () {
    $trace = app(TraceContextFactory::class)->create('exec_rel_outer');
    $context = new ExecutionContext(executionId: 'exec_rel_outer', startTime: microtime(true));
    app()->instance(TraceContext::class, $trace);
    app()->instance(ExecutionContext::class, $context);
    Event::listen(GraphExecutionStarted::class, fn () => throw new RuntimeException('listener broke'));
    relGraph('rel-fail');

    expect(fn () => AgenticGraph::execute('rel-fail', new State, relOptions(StreamingMode::NONE, ExecutionMode::SYNC)))
        ->toThrow(RuntimeException::class, 'listener broke');

    expect(relBound())->toBe([TraceContext::class, ExecutionContext::class])
        ->and(app(TraceContext::class))->toBe($trace)
        ->and(app(ExecutionContext::class))->toBe($context);
});

it('resolves the interrupt of a failed sync resume as failed, before the failure listener runs', function (StreamingMode $streaming, bool $listenerThrows) {
    Exceptions::fake();
    relGraph('rel-approval', RELApprovalNode::class);
    $handle = AgenticGraph::execute('rel-approval', new State(['fail_after_approval' => true]), relOptions($streaming, ExecutionMode::SYNC));
    $interrupt = InterruptRecord::query()->forExecution($handle->id())->latest('id')->firstOrFail();
    $atListener = null;
    Event::listen(GraphExecutionFailed::class, function () use (&$atListener, $interrupt, $listenerThrows): void {
        $fresh = $interrupt->fresh();
        $atListener = [$fresh->status(), $fresh->getAttribute('resolution')];
        if ($listenerThrows) {
            throw new RuntimeException('listener broke');
        }
    });

    expect(fn () => $handle->resume(['approved' => true]))->toThrow(RuntimeException::class, 'node broke');

    $interrupt = $interrupt->fresh();
    expect($atListener)->toBe(['resolved', ['outcome' => 'failed']])
        ->and([$interrupt->status(), $interrupt->getAttribute('resolution')])->toBe(['resolved', ['outcome' => 'failed']])
        ->and($interrupt->metadata()['resume_error']['message'])->toBe('node broke')
        ->and($interrupt->metadata()['last_error']['message'])->toBe('node broke')
        ->and(relRow($handle->id())->status)->toBe('failed');
})->with(['non-streaming' => StreamingMode::NONE, 'streaming' => StreamingMode::ITERATOR])->with(['quiet listener' => false, 'throwing listener' => true]);

it('stores the original error on a failed resume, with the interrupt resolved as failed before the listener runs', function (StreamingMode $streaming) {
    config()->set('agenticgraph.execution.resume_tries', 1);
    Exceptions::fake();
    $failed = relFailedJobs();
    $run = relQueuedResume($streaming, ['fail_after_approval' => true]);
    $atListener = null;
    Event::listen(GraphExecutionFailed::class, function () use (&$atListener, $run): void {
        $fresh = $run['interrupt']->fresh();
        $atListener = [$fresh->status(), $fresh->getAttribute('resolution')];
        throw new RuntimeException('listener broke');
    });

    relWork();

    $interrupt = $run['interrupt']->fresh();
    expect($atListener)->toBe(['resolved', ['outcome' => 'failed']])
        ->and([$interrupt->status(), $interrupt->getAttribute('resolution')])->toBe(['resolved', ['outcome' => 'failed']])
        ->and($interrupt->metadata()['resume_error']['message'])->toBe('node broke')
        ->and($interrupt->metadata()['last_error']['message'])->toBe('node broke')
        ->and(RELHandler::$calls)->toBe([['failed', ['error_type' => RuntimeException::class, 'message' => 'node broke']]])
        ->and(relRow($run['id'])->error)->toBe('node broke')
        ->and($failed->getArrayCopy())->toBe([['ResumeExecutionJob', 'node broke']]);
})->with(['non-streaming' => StreamingMode::NONE, 'streaming' => StreamingMode::SSE]);

it('reports a listener error in failed() and does not throw it', function () {
    Exceptions::fake();
    $lines = relListenerLines();
    relThrowingListener(GraphExecutionFailed::class);
    app(ExecutionRepository::class)->queued('exec_rel_failed', 'rel-missing');
    $job = new ExecuteGraphJob('rel-missing', 'exec_rel_failed', []);

    $job->failed(new RuntimeException('graph not on this worker'));

    expect(relRow('exec_rel_failed')->status)->toBe('failed')
        ->and(count($lines))->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'listener broke');
});

it('fires the event once when the stream store is down in failed(), then throws the store error', function () {
    $seen = new ArrayObject;
    Event::listen(GraphExecutionFailed::class, fn (GraphExecutionFailed $e) => $seen[] = $e->executionId);
    app(ExecutionRepository::class)->queued('exec_rel_down', 'rel-missing', [], 'stream_rel_down');
    $job = new ExecuteGraphJob('rel-missing', 'exec_rel_down', [], executionOptions: relOptions(StreamingMode::SSE), streamId: 'stream_rel_down', streamSeq: 0);
    $store = relBreakStore('graph_failed');

    expect(fn () => $job->failed(new RuntimeException('boom')))->toThrow(RuntimeException::class, 'store down at graph_failed');
    expect($seen->getArrayCopy())->toBe(['exec_rel_down']);

    $store->failOn = null; // a later failed() can still write the frames, with no second event
    $job->failed(new RuntimeException('boom'));
    expect(relTypes('stream_rel_down'))->toBe(['graph_failed', 'execution_terminal'])
        ->and(count($seen))->toBe(1);
});

it('fires the skip event once when the stream store is down, and records the job as failed with tries 3', function () {
    config()->set('agenticgraph.execution.tries', 3);
    $failed = relFailedJobs();
    $seen = new ArrayObject;
    Event::listen(GraphExecutionSkipped::class, fn (GraphExecutionSkipped $e) => $seen[] = $e->executionId);
    relGraph('rel-skip');
    $handle = AgenticGraph::execute('rel-skip', new State, relOptions(StreamingMode::SSE));
    AgenticGraph::skipQueuedRunsUsing(fn () => 'not today');
    $store = relBreakStore('graph_failed');

    relWork(3);

    expect($seen->getArrayCopy())->toBe([$handle->id()])
        ->and($failed->getArrayCopy())->toBe([['ExecuteGraphJob', 'store down at graph_failed']])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('writes reason skipped when failed() writes the end frames of a skipped run again', function () {
    relGraph('rel-skip');
    $handle = AgenticGraph::execute('rel-skip', new State, relOptions(StreamingMode::SSE));
    AgenticGraph::skipQueuedRunsUsing(fn () => 'not today');
    $store = relBreakStore('graph_failed');
    relWork();
    $streamId = (string) relRow($handle->id())->stream_id;

    $store->failOn = null;
    (new ExecuteGraphJob('rel-skip', $handle->id(), [], executionOptions: relOptions(StreamingMode::SSE), streamId: $streamId, streamSeq: 0))
        ->failed(new RuntimeException('store down at graph_failed'));

    $events = iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, 1, 200), false);
    expect(array_map(fn (StreamEvent $e) => $e->type(), $events))->toBe(['graph_failed', 'execution_terminal'])
        ->and($events[0]->payload()['reason'] ?? null)->toBe('skipped')
        ->and($events[1]->payload()['meta']['reason'] ?? null)->toBe('skipped');
});

it('records the job as failed with tries 3 when a skip listener throws', function () {
    config()->set('agenticgraph.execution.tries', 3);
    $failed = relFailedJobs();
    $lines = relListenerLines();
    $seen = relThrowingListener(GraphExecutionSkipped::class);
    relGraph('rel-skip');
    $handle = AgenticGraph::execute('rel-skip', new State, relOptions());
    AgenticGraph::skipQueuedRunsUsing(fn () => 'not today');

    relWork(3);

    expect($failed->getArrayCopy())->toBe([['ExecuteGraphJob', 'listener broke']])
        ->and($seen->getArrayCopy())->toBe([$handle->id()])
        ->and(count($lines))->toBe(1)
        ->and(relRow($handle->id())->error)->toBe('Skipped: not today');
});

// Item 3: parallel batch failure.

function relBatchRun(string $executionId): void
{
    AgenticGraph::register('rel-batch', GraphBuilder::create('rel-batch')
        ->addNode('start', RELNode::class)
        ->addNode('a', RELNode::class)
        ->addNode('b', RELNode::class)
        ->addNode('end', RELNode::class)
        ->addConditionalEdge('start', RELFork::class)
        ->addEdge('a', 'end')
        ->addEdge('b', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build());
    app(ExecutionRepository::class)->queued($executionId, 'rel-batch');
    app(ExecutionRepository::class)->started($executionId, 'rel-batch');
}

function relFailBatch(string $caller, string $executionId): void
{
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-1';
    $coordinator = app(ParallelExecutionCoordinator::class);
    match ($caller) {
        'catch callback' => $coordinator->buildBatchCallbacks('rel-batch', $executionId, 'start', ['a', 'b'], rootSpanId: 'span_root')['catch']($batch, new RuntimeException('branch broke')),
        'missing results' => $coordinator->handleBatchCompletion($batch, 'rel-batch', $executionId, 'start', ['a', 'b'], rootSpanId: 'span_root'),
        'branch job failure' => $coordinator->handleBranchJobFailure(new ExecuteNodeJob($executionId, 'a', [], 'rel-batch', 'span_root'), new RuntimeException('branch broke')),
    };
}

it('writes the batch log line and reports the listener error when it throws', function (string $caller) {
    Exceptions::fake();
    $lines = relListenerLines();
    $batchLines = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $e) use ($batchLines): void {
        if ($e->message === 'Batch execution failed') {
            $batchLines[] = $e->context['execution_id'];
        }
    });
    relThrowingListener(GraphExecutionFailed::class);
    relBatchRun('exec_rel_batch');

    relFailBatch($caller, 'exec_rel_batch');

    expect($batchLines->getArrayCopy())->toBe(['exec_rel_batch'])
        ->and(count($lines))->toBe(1)
        ->and(relRow('exec_rel_batch')->status)->toBe('failed');
    Exceptions::assertReportedCount(1);
})->with(['catch callback', 'missing results', 'branch job failure']);

it('does not leave the batch trace segment open when a listener throws', function () {
    Exceptions::fake();
    config(['agenticgraph.tracing.enabled' => true, 'agenticgraph.tracing.endpoint' => 'https://api.example.com', 'agenticgraph.tracing.api_key' => 'test-key']);
    $exporter = new RELTraceExporter;
    app()->instance(TracerExporter::class, $exporter);
    app()->instance(PulseExporter::class, $exporter);
    expect($exporter->isEnabled())->toBeTrue();
    Event::forget(GraphExecutionFailed::class); // an app listener that runs before the tracing listener
    relThrowingListener(GraphExecutionFailed::class);
    relBatchRun('exec_rel_trace');

    try {
        relFailBatch('catch callback', 'exec_rel_trace');
    } catch (RuntimeException) {
    }

    expect($exporter->getBuffer()['traces'])->toBe([]);
});

// Item 4: the log line.

/** @return array{0: string, 1: string} two queued runs past the queued timeout, in the command's order */
function relStaleRuns(): array
{
    relGraph('rel-stale');
    config()->set('agenticgraph.execution.queue_connection', 'database');
    $ids = [];
    foreach ([1, 2] as $ignored) {
        $ids[] = AgenticGraph::execute('rel-stale', new State, relOptions())->id();
    }
    DB::table('agenticgraph_executions')->update(['created_at' => now()->subSeconds(400), 'updated_at' => now()->subSeconds(400)]);
    sort($ids);

    return $ids;
}

function relNotFound(): Closure
{
    return fn () => (new ModelNotFoundException)->setModel(InterruptRecord::class);
}

function relExpectedLine(string $executionId, string $graph, string $event): array
{
    return [
        'level' => 'error',
        'execution_id' => $executionId,
        'graph_name' => $graph,
        'event' => $event,
        'error_type' => ModelNotFoundException::class,
        'message' => 'No query results for model ['.InterruptRecord::class.'].',
    ];
}

it('writes the log line in executions:expire for a ModelNotFoundException, with core logging off, and goes on', function () {
    config()->set('agenticgraph.logging.enabled', false);
    $lines = relListenerLines();
    relThrowingListener(GraphExecutionFailed::class, relNotFound());
    [$one, $two] = relStaleRuns();

    Artisan::call('agenticgraph:executions:expire');

    expect(trim(Artisan::output()))->toBe('Expired 2 execution(s).')
        ->and($lines->getArrayCopy())->toBe([
            relExpectedLine($one, 'rel-stale', GraphExecutionFailed::class),
            relExpectedLine($two, 'rel-stale', GraphExecutionFailed::class),
        ]);
});

it('writes the log line in interrupt:expire for a ModelNotFoundException, and goes on', function (string $event) {
    config()->set('agenticgraph.logging.enabled', false);
    $lines = relListenerLines();
    relGraph('rel-approval', RELApprovalNode::class);
    $ids = [];
    foreach ([1, 2] as $ignored) {
        $ids[] = AgenticGraph::execute('rel-approval', new State, relOptions())->id();
        relWork();
    }
    InterruptRecord::query()->update(['expires_at' => now()->subMinute()]);
    relThrowingListener($event, relNotFound());

    Artisan::call('agenticgraph:interrupt:expire');

    expect(trim(Artisan::output()))->toBe('Expired 2 interrupt(s).')
        ->and($lines->getArrayCopy())->toBe(array_map(fn (string $id) => relExpectedLine($id, 'rel-approval', $event), $ids));
})->with([GraphExecutionFailed::class, InterruptExpired::class]);

it('keeps the run-ending path going when the logger itself fails', function () {
    Exceptions::fake();
    Event::listen(MessageLogged::class, function (MessageLogged $e): void {
        if ($e->message === 'AgenticGraph: run-ending event listener failed') {
            throw new RuntimeException('logger broke');
        }
    });
    relThrowingListener(GraphExecutionFailed::class);
    [$one, $two] = relStaleRuns();

    Artisan::call('agenticgraph:executions:expire');

    expect(trim(Artisan::output()))->toBe('Expired 2 execution(s).');
    Exceptions::assertReportedCount(2);
});
