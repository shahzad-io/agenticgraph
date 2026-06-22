<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;

class P26StreamingHonestyEntryNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('entry_runs', ((int) $state->get('entry_runs', 0)) + 1);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->get('entry_passed', false);
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $state->set('resume_marker', $humanInput['marker'] ?? 'applied');
    }
}

class P26StreamingHonestyDownstreamNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('downstream_ran', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->get('downstream_passed', false);
    }

    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $state->set('downstream_passed', (bool) ($humanInput['downstream_passed'] ?? false));
    }
}

class P26StreamingHonestyDoneNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('finished', true);
    }
}

class P26StreamingHonestyApprovalEntryNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('reviewed', true);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
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

function registerP26DownstreamReinterruptGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('entry', P26StreamingHonestyEntryNode::class)
        ->addNode('downstream', P26StreamingHonestyDownstreamNode::class)
        ->addNode('done', P26StreamingHonestyDoneNode::class)
        ->addEdge('entry', 'downstream')
        ->addEdge('downstream', 'done')
        ->setEntryPoint('entry')
        ->addExitPoint('done')
        ->build();

    AgenticGraph::register($name, $graph);
}

function registerP26CompletionGraph(string $name): void
{
    $graph = GraphBuilder::create($name)
        ->addNode('approve', P26StreamingHonestyApprovalEntryNode::class)
        ->addNode('done', P26StreamingHonestyDoneNode::class)
        ->addEdge('approve', 'done')
        ->setEntryPoint('approve')
        ->addExitPoint('done')
        ->build();

    AgenticGraph::register($name, $graph);
}

beforeEach(function () {
    config()->set('agenticgraph.checkpoint.enabled', true);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);
});

it('reports INTERRUPTED on sync streaming handle when resume re-interrupts downstream', function () {
    $graphName = 'p26-streaming-downstream-honesty';
    registerP26DownstreamReinterruptGraph($graphName);

    $options = new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR);

    $handle = AgenticGraph::execute($graphName, new State, $options);

    expect($handle->getFinalState()->hasInterrupt())->toBeTrue();

    $resumed = $handle->resume(['marker' => 'entry-resume']);

    expect($resumed->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($resumed->isInterrupted())->toBeTrue()
        ->and($resumed->getFinalState()->hasInterrupt())->toBeTrue();
});

it('reports COMPLETED on sync streaming handle when resume completes without downstream interrupt', function () {
    $graphName = 'p26-streaming-complete-honesty';
    registerP26CompletionGraph($graphName);

    $options = new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::ITERATOR);

    $handle = AgenticGraph::execute($graphName, new State, $options);

    expect($handle->getFinalState()->hasInterrupt())->toBeTrue();

    $resumed = $handle->resume(['approved' => true, 'comments' => 'ok']);

    expect($resumed->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($resumed->isInterrupted())->toBeFalse()
        ->and($resumed->getFinalState()->get('finished'))->toBeTrue()
        ->and($resumed->getFinalState()->get('reviewed'))->toBeTrue()
        ->and($resumed->getFinalState()->hasInterrupt())->toBeFalse();
});

it('sync none resume handle honesty unchanged for downstream re-interrupt', function () {
    $graphName = 'p26-sync-none-honesty';
    registerP26DownstreamReinterruptGraph($graphName);

    $options = new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::NONE);

    $handle = AgenticGraph::execute($graphName, new State, $options);

    $resumed = $handle->resume(['marker' => 'sync-none']);

    expect($resumed->status())->toBe(ExecutionStatus::INTERRUPTED)
        ->and($resumed->isInterrupted())->toBeTrue()
        ->and($resumed->getFinalState()->hasInterrupt())->toBeTrue();
});
