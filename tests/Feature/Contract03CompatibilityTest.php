<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionResumer;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Execution\InterruptResult;
use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamResumption;

/**
 * An app's own implementations of the 0.3 interfaces keep working: 0.4 adds no parameter to them.
 */
class Contract03Node extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
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
        return $state->set('approved', true);
    }
}

/** Written against 0.3.0: its exact signatures, delegating to the package's manager. */
function contract03InterruptService(InterruptServiceInterface $inner, ArrayObject $resumed): InterruptServiceInterface
{
    return new class($inner, $resumed) implements InterruptServiceInterface
    {
        public function __construct(private InterruptServiceInterface $inner, private ArrayObject $resumed) {}

        public function shouldInterrupt(NodeInterface $node, StateContract $state): bool
        {
            return $this->inner->shouldInterrupt($node, $state);
        }

        public function handle(string $executionId, string $graphName, string $nodeName, StateContract $state, ?StreamContext $streamContext = null): InterruptResult
        {
            return $this->inner->handle($executionId, $graphName, $nodeName, $state, $streamContext);
        }

        public function resume(string $checkpointId): StateContract
        {
            return $this->inner->resume($checkpointId);
        }

        public function resumeWithInput(string $checkpointId, mixed $humanInput): StateContract
        {
            $this->resumed[] = func_get_args();

            return $this->inner->resumeWithInput($checkpointId, $humanInput);
        }
    };
}

/** An interrupted run and its queued resume job. */
function contract03ResumeJob(): ResumeExecutionJob
{
    AgenticGraph::register('contract-03', GraphBuilder::create('contract-03')
        ->addNode('work', Contract03Node::class)
        ->setEntryPoint('work')
        ->addExitPoint('work')
        ->build());
    Bus::fake();
    $handle = AgenticGraph::execute('contract-03', new State, new ExecutionOptions(mode: ExecutionMode::ASYNC, interrupts: true));
    app()->call([Bus::dispatched(ExecuteGraphJob::class)->first(), 'handle']);
    AgenticGraph::getExecution($handle->id())->resume(['approved' => true]);

    return Bus::dispatched(ResumeExecutionJob::class)->first();
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
    config()->set('agenticgraph.execution.queue_connection', 'sync');
});

it('an InterruptServiceInterface written for 0.3 satisfies the interface and resumes a queued run', function () {
    $job = contract03ResumeJob();
    $id = (string) InterruptRecord::find($job->interruptId)->executionId();
    $resumed = new ArrayObject;
    $custom = contract03InterruptService(app(InterruptServiceInterface::class), $resumed);

    $job->handle($custom, app(StreamResumption::class));

    expect($custom)->toBeInstanceOf(InterruptServiceInterface::class)
        ->and($resumed)->toHaveCount(1)
        ->and($resumed[0])->toHaveCount(2)
        ->and(app(ExecutionRepository::class)->getStatus($id))->toBe('completed');
});

// The deleted-run guard does not depend on the app's service passing anything on.
it('a queued resume through a 0.3 service creates nothing when the row goes after the job check', function () {
    $job = contract03ResumeJob();
    $id = (string) InterruptRecord::find($job->interruptId)->executionId();
    $custom = contract03InterruptService(app(InterruptServiceInterface::class), new ArrayObject);
    $deleted = false;
    DB::listen(function ($query) use ($id, &$deleted): void {
        if (! $deleted && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'agenticgraph_executions')) {
            $deleted = true;
            DB::table('agenticgraph_executions')->where('id', $id)->delete();
        }
    });

    $job->handle($custom, app(StreamResumption::class));

    expect($deleted)->toBeTrue()
        ->and(DB::table('agenticgraph_executions')->where('id', $id)->count())->toBe(0);
});

it('an ExecutionResumer written for 0.3 satisfies the interface', function () {
    $resumer = new class implements ExecutionResumer
    {
        public function __invoke(Graph $graph, StateContract $state, string $startNode, string $executionId, bool $useBatch = false, ?LoopMetadata $loopMetadata = null, bool $skipInterruptGateForEntry = false): StateContract
        {
            return $state;
        }
    };

    expect($resumer)->toBeInstanceOf(ExecutionResumer::class);
});
