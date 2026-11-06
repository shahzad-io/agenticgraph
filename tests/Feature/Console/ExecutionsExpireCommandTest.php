<?php

use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\GraphExecutionFailed;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceSegmentMerger;

class StaleExporter extends PulseExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}

class StaleRecordingBroadcaster implements Broadcaster
{
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
        throw new RuntimeException('Reverb down');
    }
}

class StaleNoopNode extends AbstractNode
{
    public static int $ran = 0;

    public function execute(StateContract $state): StateContract
    {
        self::$ran++;

        return $state;
    }
}

/** Backdates the execution row, as a long run with no progress would look. */
class StaleBackdateNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $old = now()->subSeconds(5000);
        DB::table('agenticgraph_executions')->update(['started_at' => $old, 'updated_at' => $old]);

        return $state;
    }
}

/** Runs the expire command in the middle of the run and records the row status it left. */
class StaleProbeNode extends AbstractNode
{
    public static ?string $statusAfterExpire = null;

    public function execute(StateContract $state): StateContract
    {
        Artisan::call('agenticgraph:executions:expire');
        self::$statusAfterExpire = DB::table('agenticgraph_executions')->value('status');

        return $state;
    }
}

function staleGraph(string $name): void
{
    AgenticGraph::register($name, GraphBuilder::create($name)
        ->addNode('a', StaleNoopNode::class)
        ->setEntryPoint('a')
        ->addExitPoint('a')
        ->build());
}

/**
 * An async execution queued as the package leaves it, then aged: status, and seconds since created, started and updated.
 *
 * @return array{id: string, stream_id: string}
 */
function staleRun(string $name, string $status = 'queued', int $age = 1000, ?int $updatedAge = null, StreamingMode $streaming = StreamingMode::SSE, ExecutionMode $mode = ExecutionMode::ASYNC): array
{
    staleGraph($name);
    Bus::fake();
    $handle = AgenticGraph::execute($name, new State, new ExecutionOptions(mode: $mode, streaming: $streaming));
    $id = $handle->id();
    $row = DB::table('agenticgraph_executions')->where('id', $id)->first();
    $update = ['status' => $status, 'created_at' => now()->subSeconds($age), 'updated_at' => now()->subSeconds($updatedAge ?? $age)];
    if ($status === 'running') {
        $update['started_at'] = now()->subSeconds($age);
    }
    DB::table('agenticgraph_executions')->where('id', $id)->update($update);

    return ['id' => $id, 'stream_id' => (string) $row->stream_id];
}

function staleStatus(string $id): ?string
{
    return DB::table('agenticgraph_executions')->where('id', $id)->value('status');
}

/**
 * @return list<StreamEvent>
 */
function staleEvents(string $streamId): array
{
    return array_values(iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, 1, 100), false));
}

beforeEach(function () {
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.execution.queued_timeout', 300);
    config()->set('agenticgraph.execution.stale_grace', 60);
    config()->set('agenticgraph.execution.timeout', 100);
    config()->set('agenticgraph.execution.resume_timeout', 0);
    config()->set('agenticgraph.execution.stale_running_after', 900);
    app()->forgetInstance(StreamStoreInterface::class);
    StaleNoopNode::$ran = 0;
    StaleProbeNode::$statusAfterExpire = null;
});

it('fails a queued execution past queued_timeout, ends its stream and fires GraphExecutionFailed with a reason', function () {
    $run = staleRun('stale-queued', 'queued', 400);
    Event::fake([GraphExecutionFailed::class]);

    $this->artisan('agenticgraph:executions:expire')->expectsOutput('Expired 1 execution(s).')->assertSuccessful();

    $events = staleEvents($run['stream_id']);
    expect(staleStatus($run['id']))->toBe('failed')
        ->and(array_map(fn (StreamEvent $e) => $e->type(), $events))->toBe(['graph_failed', 'execution_terminal'])
        ->and($events[0]->payload()['reason'])->toBe('stale_queued')
        ->and($events[1]->payload()['status'])->toBe('failed');
    Event::assertDispatched(GraphExecutionFailed::class, fn (GraphExecutionFailed $e) => $e->executionId === $run['id'] && $e->reason === 'stale_queued');
    Event::assertDispatchedTimes(GraphExecutionFailed::class, 1);
});

it('fails a running async execution past timeout plus grace with reason stale_running', function () {
    $run = staleRun('stale-running', 'running', 200);
    Event::fake([GraphExecutionFailed::class]);

    $this->artisan('agenticgraph:executions:expire')->expectsOutput('Expired 1 execution(s).')->assertSuccessful();

    $events = staleEvents($run['stream_id']);
    expect(staleStatus($run['id']))->toBe('failed')
        ->and($events[0]->payload()['reason'])->toBe('stale_running')
        ->and(end($events)->type())->toBe('execution_terminal');
    Event::assertDispatched(GraphExecutionFailed::class, fn (GraphExecutionFailed $e) => $e->reason === 'stale_running');
});

it('uses the larger of the execution and resume timeouts for a running execution', function () {
    config()->set('agenticgraph.execution.resume_timeout', 400);
    $run = staleRun('stale-resume-bound', 'running', 300);

    Artisan::call('agenticgraph:executions:expire');

    expect(staleStatus($run['id']))->toBe('running');

    DB::table('agenticgraph_executions')->where('id', $run['id'])->update(['started_at' => now()->subSeconds(500), 'updated_at' => now()->subSeconds(500)]);
    Artisan::call('agenticgraph:executions:expire');

    expect(staleStatus($run['id']))->toBe('failed');
});

it('uses stale_running_after when execution.timeout is 0', function () {
    config()->set('agenticgraph.execution.timeout', 0);
    $recent = staleRun('stale-zero-recent', 'running', 500);
    $old = staleRun('stale-zero-old', 'running', 1000);

    $this->artisan('agenticgraph:executions:expire')->expectsOutput('Expired 1 execution(s).')->assertSuccessful();

    expect(staleStatus($recent['id']))->toBe('running')
        ->and(staleStatus($old['id']))->toBe('failed');
});

it('leaves executions that are not stale, interrupted, sync or finished', function () {
    $freshQueued = staleRun('stale-fresh-queued', 'queued', 100);
    $insideBound = staleRun('stale-inside', 'running', 150);
    $recentProgress = staleRun('stale-progress', 'running', 5000, 10);
    $interrupted = staleRun('stale-interrupted', 'interrupted', 5000);
    $sync = staleRun('stale-sync', 'running', 5000, null, StreamingMode::SSE, ExecutionMode::SYNC);
    $completed = staleRun('stale-completed', 'completed', 5000);

    $this->artisan('agenticgraph:executions:expire')->expectsOutput('Expired 0 execution(s).')->assertSuccessful();

    expect(array_map(fn (array $r) => staleStatus($r['id']), [$freshQueued, $insideBound, $recentProgress, $interrupted, $sync, $completed]))
        ->toBe(['queued', 'running', 'running', 'interrupted', 'running', 'completed']);
});

it('does not expire an execution that makes progress between the candidate query and the lock', function () {
    $run = staleRun('stale-race', 'queued', 400);
    $touched = false;
    DB::listen(function ($query) use ($run, &$touched) {
        if (! $touched && str_contains($query->sql, 'limit') && str_contains($query->sql, 'agenticgraph_executions')) {
            $touched = true;
            DB::table('agenticgraph_executions')->where('id', $run['id'])->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->artisan('agenticgraph:executions:expire')->expectsOutput('Expired 0 execution(s).')->assertSuccessful();

    expect($touched)->toBeTrue()
        ->and(staleStatus($run['id']))->toBe('running')
        ->and(staleEvents($run['stream_id']))->toBe([]);
});

it('expires each execution completely or not at all, independently of the others', function () {
    $broken = staleRun('stale-broken', 'queued', 400);
    $healthy = staleRun('stale-healthy', 'queued', 400);
    Log::spy();
    app()->instance(ExecutionRepository::class, new class($broken['id']) extends ExecutionRepository
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

    $this->artisan('agenticgraph:executions:expire')->expectsOutput('Expired 1 execution(s).')->assertSuccessful();

    expect(staleStatus($broken['id']))->toBe('queued')
        ->and(staleEvents($broken['stream_id']))->toBe([])
        ->and(staleStatus($healthy['id']))->toBe('failed');
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'execution expiry failed') && ($context['execution_id'] ?? null) === $broken['id'])->once();
});

it('still fails the execution and stores the terminal when the broadcast fails, and logs it', function () {
    $run = staleRun('stale-broadcast', 'queued', 400, null, StreamingMode::BROADCAST);
    app()->instance(Broadcaster::class, new StaleRecordingBroadcaster);
    Log::spy();

    $this->artisan('agenticgraph:executions:expire')->expectsOutput('Expired 1 execution(s).')->assertSuccessful();

    expect(staleStatus($run['id']))->toBe('failed')
        ->and(array_map(fn (StreamEvent $e) => $e->type(), staleEvents($run['stream_id'])))->toBe(['graph_failed', 'execution_terminal']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['message'] ?? null) === 'Reverb down')->once();
});

function staleTracedRun(string $name): array
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
        'agenticgraph.tracing.api_key' => 'test-key',
    ]);
    app()->forgetInstance('request');
    $exporter = new StaleExporter;
    app()->instance(TracerExporter::class, $exporter);
    app()->instance(PulseExporter::class, $exporter);

    return [$exporter, staleRun($name, 'queued', 400)];
}

it('ends the trace with one failed segment on the original root span, naming the graph', function () {
    [$exporter, $run] = staleTracedRun('stale-trace');
    $options = json_decode((string) DB::table('agenticgraph_executions')->where('id', $run['id'])->value('execution_options'), true);

    Artisan::call('agenticgraph:executions:expire');

    $payload = $exporter->sent[0] ?? null;
    $roots = array_values(array_filter($payload['spans'] ?? [], fn (array $span) => $span['parent_id'] === null));
    $merged = (new TraceSegmentMerger)->merge($exporter->sent);
    expect($exporter->sent)->toHaveCount(1)
        ->and($options['trace_root_span_id'])->toBeString()
        ->and($payload['segment']['kind'])->toBe('continued')
        ->and($payload['segment']['final'])->toBeTrue()
        ->and($payload['trace']['status'])->toBe('failed')
        ->and($payload['trace']['graph_name'])->toBe('stale-trace')
        ->and($roots)->toHaveCount(1)
        ->and($roots[0]['id'])->toBe($options['trace_root_span_id'])
        ->and($roots[0]['status'])->toBe('failed')
        ->and(strtotime($payload['segment']['ended_at']))->toBeGreaterThanOrEqual(strtotime($payload['segment']['started_at']))
        ->and($merged['trace']['graph_name'])->toBe('stale-trace');
});

it('sends no trace segment when the expiry rolls back, and exactly one on the next run', function () {
    [$exporter, $run] = staleTracedRun('stale-trace-rollback');
    $fail = true;
    Event::listen(GraphExecutionFailed::class, function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('listener failed');
        }
    });

    Artisan::call('agenticgraph:executions:expire');

    expect($exporter->sent)->toBe([])
        ->and(staleStatus($run['id']))->toBe('queued');

    $exporter->reset();
    $fail = false;
    Artisan::call('agenticgraph:executions:expire');

    expect($exporter->sent)->toHaveCount(1)
        ->and(staleStatus($run['id']))->toBe('failed');
});

it('skips a queued job whose execution already ended, without running a node or writing events', function () {
    $run = staleRun('stale-late-job', 'queued', 400);
    Artisan::call('agenticgraph:executions:expire');
    $eventsBefore = count(staleEvents($run['stream_id']));
    $row = DB::table('agenticgraph_executions')->where('id', $run['id'])->first();

    $job = new ExecuteGraphJob('stale-late-job', $run['id'], [], null, 'span_x', ExecutionOptions::fromStored($row->execution_options), $run['stream_id'], $eventsBefore);
    app()->call([$job, 'handle']);

    expect(StaleNoopNode::$ran)->toBe(0)
        ->and(staleStatus($run['id']))->toBe('failed')
        ->and(count(staleEvents($run['stream_id'])))->toBe($eventsBefore);
});

it('does not expire a long sequential async run that keeps completing nodes', function (StreamingMode $streaming) {
    AgenticGraph::register('stale-sequential', GraphBuilder::create('stale-sequential')
        ->addNode('a', StaleNoopNode::class)
        ->addNode('b', StaleBackdateNode::class)
        ->addNode('c', StaleProbeNode::class)
        ->addEdge('a', 'b')
        ->addEdge('b', 'c')
        ->setEntryPoint('a')
        ->addExitPoint('c')
        ->build());
    Bus::fake();
    $handle = AgenticGraph::execute('stale-sequential', new State, new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: $streaming));
    $job = Bus::dispatched(ExecuteGraphJob::class)->first(fn (ExecuteGraphJob $j) => $j->executionId === $handle->id());

    // Node b backdates the row (no progress for 5000 s); finishing b is the progress that node c's expire call must see.
    app()->call([$job, 'handle']);

    expect(StaleProbeNode::$statusAfterExpire)->toBe('running')
        ->and(staleStatus($handle->id()))->toBe('completed');
})->with([
    'no streaming' => [StreamingMode::NONE],
    'sse' => [StreamingMode::SSE],
]);

it('reports progress from a parallel branch job, so a fan-out run is not expired', function () {
    $router = new class implements RouterInterface
    {
        public function route(StateContract $state): string|array
        {
            return ['x', 'y'];
        }

        public function getAvailableRoutes(): array
        {
            return ['x', 'y'];
        }

        public function getDefaultRoute(): ?string
        {
            return null;
        }

        public function validateRoute(string $route): bool
        {
            return true;
        }
    };
    $graph = GraphBuilder::create('stale-fanout')
        ->addNode('start', StaleNoopNode::class)
        ->addNode('x', StaleNoopNode::class)
        ->addNode('y', StaleNoopNode::class)
        ->addNode('end', StaleNoopNode::class)
        ->addConditionalEdge('start', $router)
        ->addEdge('x', 'end')
        ->addEdge('y', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build();
    app(GraphRegistry::class)->register('stale-fanout', $graph);
    $fake = Bus::fake();
    $executionId = 'exec_stale_fanout';
    app(ExecutionRepository::class)->queued($executionId, 'stale-fanout', [], null, new ExecutionOptions(mode: ExecutionMode::ASYNC));
    app()->call([new ExecuteGraphJob('stale-fanout', $executionId, [], null, 'span_fan'), 'handle']);

    /** @var PendingBatch|null $pending */
    $pending = null;
    $fake->assertBatched(function (PendingBatch $batch) use (&$pending): bool {
        $pending = $batch;

        return true;
    });
    $old = now()->subSeconds(5000);
    DB::table('agenticgraph_executions')->where('id', $executionId)->update(['status' => 'running', 'started_at' => $old, 'updated_at' => $old]);
    $job = $pending->jobs->first();
    expect($job)->toBeInstanceOf(ExecuteNodeJob::class);

    app()->call([unserialize(serialize($job)), 'handle']);

    $updated = DB::table('agenticgraph_executions')->where('id', $executionId)->value('updated_at');
    expect(strtotime((string) $updated))->toBeGreaterThan(now()->subSeconds(60)->getTimestamp());
});
