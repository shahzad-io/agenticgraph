<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Events\GraphExecutionSkipped;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeResumed;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ContinueExecutionJob;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamResumption;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/*
 * Core 0.4.3: every job end leaves no scope binding behind. A worker keeps instance() bindings between jobs, so a
 * leftover Graph or ExecutionContext gives the next run the wrong graph or old loop counts.
 */

class JSBNode extends PrismNode
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
        if ($state->get('delete')) {
            DB::table('agenticgraph_executions')->delete();
        }

        return $state->set('done', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return (bool) $state->get('pause') && ! $state->has('approved');
    }

    public function getInterruptReason(StateContract $state): string
    {
        return 'Needs approval';
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $this->handleApproval($state, true, null);
    }
}

class JSBProbeNode extends JSBNode
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

const JSB_BINDINGS = [TraceContext::class, ExecutionClock::class, ExecutionContext::class, LoopMetadata::class, Graph::class, StreamContext::class];

function jsbForget(): void
{
    foreach (JSB_BINDINGS as $binding) {
        app()->forgetInstance($binding);
    }
}

function jsbGraph(string $name, string $node = JSBNode::class): void
{
    AgenticGraph::register($name, GraphBuilder::create($name)
        ->addNode('step', $node)
        ->setEntryPoint('step')
        ->addExitPoint('step')
        ->build());
}

/**
 * A job of the given kind, ready to run, with the container clean.
 *
 * @param  array<string, mixed>  $state
 */
function jsbJob(string $kind, StreamingMode $streaming, array $state): object
{
    jsbGraph('jsb-first');
    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: $streaming, interrupts: true);
    Bus::fake();

    if ($kind === 'resume') {
        // The state of the interrupt checkpoint decides how the resumed node ends.
        $handle = AgenticGraph::execute('jsb-first', new State(['pause' => true] + $state), $options);
        Bus::dispatched(ExecuteGraphJob::class)->first()->handle(app(GraphExecutor::class), app(GraphRegistry::class));
        $handle->refresh()->resume(['approved' => true]);
        $job = Bus::dispatched(ResumeExecutionJob::class)->first();
    } else {
        $id = 'exec_jsb_'.$kind;
        app(ExecutionRepository::class)->queued($id, 'jsb-first', State::toStoragePayload(new State($state)), $streaming === StreamingMode::NONE ? null : 'stream_jsb', $options);
        if ($kind !== 'execute') {
            app(ExecutionRepository::class)->started($id, 'jsb-first');
        }
        $streamId = $streaming === StreamingMode::NONE ? null : 'stream_jsb';
        $job = match ($kind) {
            'execute' => new ExecuteGraphJob('jsb-first', $id, $state, executionOptions: $options, streamId: $streamId),
            'continue' => new ContinueExecutionJob('jsb-first', $id, $state, 'step', null, $options, $streamId, rootSpanId: 'span_root'),
            'continue with loop' => new ContinueExecutionJob('jsb-first', $id, $state, 'step', ['global_iterations' => 4, 'path_stack' => ['step'], 'node_visit_counts' => ['step' => 4], 'cycle_completion_counts' => [], 'node_index_map' => ['step' => [0]]], $options, $streamId),
            'branch' => new ExecuteNodeJob($id, 'step', $state, 'jsb-first', 'span_root', ['global_iterations' => 4, 'path_stack' => ['step'], 'node_visit_counts' => ['step' => 4], 'cycle_completion_counts' => [], 'node_index_map' => ['step' => [0]]]),
        };
    }
    jsbForget();

    return $job;
}

/** Runs the job as a worker does: handle(), and failed() when it throws. */
function jsbRun(object $job): ?Throwable
{
    try {
        match ($job::class) {
            ResumeExecutionJob::class => $job->handle(app(InterruptServiceInterface::class), app(StreamResumption::class)),
            ExecuteNodeJob::class => $job->handle(app(GraphRegistry::class), app(NodeMiddlewarePipeline::class)),
            default => $job->handle(app(GraphExecutor::class), app(GraphRegistry::class)),
        };
    } catch (Throwable $e) {
        if (method_exists($job, 'failed')) {
            try {
                $job->failed($e);
            } catch (Throwable) {
            }
        }

        return $e;
    }

    return null;
}

function jsbExpectClean(): void
{
    $bound = array_values(array_filter(JSB_BINDINGS, fn (string $binding) => app()->bound($binding)));
    jsbGraph('jsb-second', JSBProbeNode::class);
    JSBProbeNode::$seen = [];
    AgenticGraph::execute('jsb-second', new State, new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE));

    expect([$bound, JSBProbeNode::$seen])->toBe([[], ['graph' => 'jsb-second', 'visits' => ['step' => 1], 'iterations' => 1]]);
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    jsbForget();
});

dataset('jsb jobs', [
    'execute' => ['execute', StreamingMode::NONE],
    'execute streaming' => ['execute', StreamingMode::SSE],
    'continue' => ['continue', StreamingMode::NONE],
    'continue streaming' => ['continue', StreamingMode::SSE],
    'continue with loop' => ['continue with loop', StreamingMode::NONE],
    'resume' => ['resume', StreamingMode::NONE],
    'resume streaming' => ['resume', StreamingMode::SSE],
    'branch' => ['branch', StreamingMode::NONE],
]);

it('leaves no binding after a successful job', function (string $kind, StreamingMode $streaming) {
    $job = jsbJob($kind, $streaming, []);

    expect(jsbRun($job))->toBeNull();

    jsbExpectClean();
})->with('jsb jobs');

it('leaves no binding after a node failure', function (string $kind, StreamingMode $streaming) {
    $job = jsbJob($kind, $streaming, ['boom' => true]);

    expect(jsbRun($job)?->getMessage())->toBe('node broke');

    jsbExpectClean();
})->with('jsb jobs');

it('leaves no binding after a skipped job', function (string $kind, StreamingMode $streaming) {
    $job = jsbJob($kind, $streaming, []);
    AgenticGraph::skipQueuedRunsUsing(fn () => 'not today');

    expect(jsbRun($job))->toBeNull();
    AgenticGraph::skipQueuedRunsUsing(null);

    jsbExpectClean();
})->with('jsb jobs');

it('leaves no binding after a job of a deleted run', function (string $kind, StreamingMode $streaming) {
    $job = jsbJob($kind, $streaming, []);
    DB::table('agenticgraph_executions')->delete();

    expect(jsbRun($job))->toBeNull();

    jsbExpectClean();
})->with('jsb jobs');

it('leaves no binding after a run deleted while the job runs it', function (string $kind, StreamingMode $streaming) {
    $job = jsbJob($kind, $streaming, ['delete' => true]);

    expect(jsbRun($job))->toBeNull();

    jsbExpectClean();
})->with('jsb jobs');

it('leaves no binding after a listener error', function (string $kind, StreamingMode $streaming, string $event) {
    Exceptions::fake();
    // A branch job ends no run: its listener errors come from the node events.
    if ($kind === 'branch' && in_array($event, [GraphExecutionCompleted::class, GraphExecutionFailed::class, NodeResumed::class], true)) {
        $event = NodeExecutionCompleted::class;
    }
    $job = jsbJob($kind, $streaming, $event === GraphExecutionFailed::class ? ['boom' => true] : []);
    if ($event === GraphExecutionSkipped::class) {
        AgenticGraph::skipQueuedRunsUsing(fn () => 'not today');
    }
    Event::listen($event, fn () => throw new RuntimeException('listener broke'));

    jsbRun($job);
    Event::forget($event);
    AgenticGraph::skipQueuedRunsUsing(null);

    jsbExpectClean();
})->with('jsb jobs')->with([
    'completion listener' => GraphExecutionCompleted::class,
    'failure listener' => GraphExecutionFailed::class,
    'skip listener' => GraphExecutionSkipped::class,
]);

it('leaves no binding when a resume throws before the run starts', function (StreamingMode $streaming) {
    $job = jsbJob('resume', $streaming, []);
    Event::listen(NodeResumed::class, fn () => throw new RuntimeException('listener broke'));

    expect(jsbRun($job)?->getMessage())->toBe('listener broke');
    Event::forget(NodeResumed::class);

    jsbExpectClean();
})->with(['non-streaming' => StreamingMode::NONE, 'streaming' => StreamingMode::SSE]);

it('keeps the bindings of an outer run when a job runs inside it on the sync queue', function () {
    $job = jsbJob('execute', StreamingMode::NONE, []);
    $outer = new ExecutionContext(executionId: 'outer', startTime: microtime(true));
    app()->instance(ExecutionContext::class, $outer);

    jsbRun($job);

    expect(app(ExecutionContext::class))->toBe($outer);
});
