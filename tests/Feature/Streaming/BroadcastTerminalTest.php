<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Shahzad\AgenticGraph\Contracts\StateContract;
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
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\StreamEventEnvelope;
use Shahzad\AgenticGraph\Testing\Llm;

class TerminalAnswerNode extends PrismNode
{
    protected ?string $model = 'openai:test-model';

    public function execute(StateContract $state): StateContract
    {
        if ($state->get('fail')) {
            throw new RuntimeException('boom');
        }

        return $state->set('answer', $this->llm()->prompt('a')->text());
    }
}

class TerminalApprovalNode extends PrismNode
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
}

function terminalGraph(string $name, string $node = TerminalAnswerNode::class): void
{
    Llm::fake(['hello world']);
    AgenticGraph::register($name, GraphBuilder::create($name)
        ->addNode('agent', $node)
        ->setEntryPoint('agent')
        ->addExitPoint('agent')
        ->build());
}

/** Runs an async broadcast graph through its queued job, as a worker does. */
function terminalAsyncRun(string $name, string $node): string
{
    terminalGraph($name, $node);
    Bus::fake();
    $handle = AgenticGraph::execute($name, new State, new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST, interrupts: true));
    Bus::dispatched(ExecuteGraphJob::class)
        ->first(fn (ExecuteGraphJob $job) => $job->executionId === $handle->id())
        ->handle(app(GraphExecutor::class), app(GraphRegistry::class));

    return $handle->id();
}

function terminalStored(string $streamId): array
{
    return iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, 1, 5000), false);
}

/**
 * The broadcast execution_terminal is the stored one (same body, same seq), sent once, and stream_close follows it
 * as the last frame.
 */
function expectStoredTerminalThenClose(object $stream, string $streamId, string $status): void
{
    $stored = terminalStored($streamId);
    $terminal = end($stored);
    $events = array_column($stream->frames, 'event');
    $at = array_keys($events, 'execution_terminal');

    expect($terminal->type())->toBe('execution_terminal')
        ->and($terminal->payload()['status'])->toBe($status)
        ->and($at)->toHaveCount(1)
        ->and($stream->frames[$at[0]]['payload'])->toBe(StreamEventEnvelope::fromStreamEvent($terminal)->toWireArray())
        ->and($stream->frames[$at[0]]['payload']['seq'])->toBe($terminal->seq())
        ->and(array_slice($events, $at[0]))->toBe(['execution_terminal', 'stream_close']);
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    app()->forgetInstance(StreamStoreInterface::class);
    $this->appBroadcaster = frameRecorder();
    $this->stream = frameRecorder();
    useStreamBroadcaster($this->appBroadcaster, $this->stream);
});

afterEach(function () {
    // Every path sends through StreamSinkFactory::broadcastSink(), so none goes to the app broadcaster directly.
    expect($this->appBroadcaster->frames)->toBe([]);
});

// From the scratch reproduction "gap 1 ... completed run".
it('gap 1: broadcasts execution_terminal with the stored seq, completed run', function () {
    terminalGraph('terminal-completed');

    $handle = AgenticGraph::broadcast('terminal-completed', new State, async: false);

    expectStoredTerminalThenClose($this->stream, (string) $handle->streamId(), 'completed');
});

// From the scratch reproduction "gap 1 ... failed run".
it('gap 1: broadcasts execution_terminal with the stored seq, failed run', function () {
    terminalGraph('terminal-failed');

    try {
        AgenticGraph::broadcast('terminal-failed', new State(['fail' => true]), async: false);
    } catch (RuntimeException) {
    }

    expectStoredTerminalThenClose($this->stream, $this->stream->frames[0]['payload']['stream_id'], 'failed');
});

it('broadcasts the stored terminal before stream_close when a job fails (EndsFailedExecution)', function () {
    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST);
    app(ExecutionRepository::class)->queued('exec_job_fail', 'not-on-worker', [], 'stream_job_fail', $options);
    $job = new ExecuteGraphJob(graphName: 'not-on-worker', executionId: 'exec_job_fail', stateData: [], rootSpanId: 'span_root', executionOptions: $options, streamId: 'stream_job_fail', streamSeq: 0);

    try {
        dispatch($job);
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    expectStoredTerminalThenClose($this->stream, 'stream_job_fail', 'failed');
});

it('broadcasts the interrupted terminal and no stream_close', function () {
    $executionId = terminalAsyncRun('terminal-interrupted', TerminalApprovalNode::class);
    $streamId = (string) DB::table('agenticgraph_executions')->where('id', $executionId)->value('stream_id');
    $stored = terminalStored($streamId);
    $terminal = end($stored);

    expect($terminal->payload()['status'])->toBe('interrupted')
        ->and(end($this->stream->frames)['event'])->toBe('execution_terminal')
        ->and(end($this->stream->frames)['payload'])->toBe(StreamEventEnvelope::fromStreamEvent($terminal)->toWireArray())
        ->and(array_column($this->stream->frames, 'event'))->not->toContain('stream_close');
});

it('broadcasts the failed terminal before stream_close when an interrupt expires', function () {
    $executionId = terminalAsyncRun('terminal-interrupt-expired', TerminalApprovalNode::class);
    $streamId = (string) DB::table('agenticgraph_executions')->where('id', $executionId)->value('stream_id');
    InterruptRecord::query()->forExecution($executionId)->update(['expires_at' => now()->subMinute()]);
    $this->stream->frames = [];

    Artisan::call('agenticgraph:interrupt:expire');

    expectStoredTerminalThenClose($this->stream, $streamId, 'failed');
    expect(array_column($this->stream->frames, 'event'))->toBe(['execution_terminal', 'stream_close']);
});

it('broadcasts the failed terminal before stream_close when executions:expire ends a dead worker\'s run', function () {
    config()->set('agenticgraph.execution.timeout', 100);
    config()->set('agenticgraph.execution.stale_grace', 60);
    terminalGraph('terminal-dead-worker');
    Bus::fake();
    $handle = AgenticGraph::execute('terminal-dead-worker', new State, new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::BROADCAST));
    DB::table('agenticgraph_executions')->where('id', $handle->id())->update(['status' => 'running', 'started_at' => now()->subSeconds(1000), 'updated_at' => now()->subSeconds(1000)]);

    Artisan::call('agenticgraph:executions:expire');

    expectStoredTerminalThenClose($this->stream, (string) $handle->streamId(), 'failed');
    expect(array_column($this->stream->frames, 'event'))->toBe(['execution_terminal', 'stream_close']);
});

it('broadcasts the terminal under an event filter that does not list it', function () {
    terminalGraph('terminal-filter');

    $handle = AgenticGraph::broadcast('terminal-filter', new State, events: ['text_delta'], async: false);

    expectStoredTerminalThenClose($this->stream, (string) $handle->streamId(), 'completed');
    expect(array_values(array_unique(array_column(unpackFrames($this->stream->frames), 0))))->toBe(['text_delta', 'execution_terminal', 'stream_close']);
});
