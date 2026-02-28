<?php

use Shahzad\AgenticGraph\Contracts\InterruptHandlerInterface;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Jobs\ResumeExecutionJob;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;
use Shahzad\AgenticGraph\Streaming\StreamSession;

class InterruptManagerNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('executed', true);
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
        if (is_array($humanInput)) {
            return $state->set('approved', $humanInput['approved'] ?? false);
        }

        return $state;
    }
}

class InterruptManagerHandler implements InterruptHandlerInterface
{
    public static array $handled = [];

    public static array $resolved = [];

    public static function reset(): void
    {
        self::$handled = [];
        self::$resolved = [];
    }

    public function handle(InterruptRecord $interrupt): InterruptRecord
    {
        self::$handled[] = $interrupt->interruptId();

        return $interrupt;
    }

    public function resolved(InterruptRecord $interrupt, mixed $resolution): void
    {
        self::$resolved[] = $interrupt->interruptId();
    }

    public function failed(InterruptRecord $interrupt, mixed $context): void {}
}

it('captures stream context metadata on interrupt', function () {
    $graph = GraphBuilder::create('interrupt-stream-context')
        ->addNode('review', InterruptManagerNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    app(GraphRegistry::class)->register('interrupt-stream-context', $graph);

    $manager = app(InterruptServiceInterface::class);

    $session = new StreamSession(
        streamId: 'stream-1',
        executionId: 'exec-1',
        options: new StreamOptions,
        startedAtUnix: time(),
        seq: 7
    );
    $context = new StreamContext($session);

    $result = $manager->handle('exec-1', 'interrupt-stream-context', 'review', new State, $context);

    $checkpoint = app(CheckpointManager::class)->load($result->checkpointId);

    expect($checkpoint)->not->toBeNull();
    expect($checkpoint->metadata['stream_id'])->toBe('stream-1');
    expect($checkpoint->metadata['last_seq'])->toBe(7);
    expect($checkpoint->metadata['stream_cursor'])->toBe(7);
    expect($result->hasStreamContext)->toBeTrue();
});

it('creates interrupt without stream metadata when no context is provided', function () {
    $graph = GraphBuilder::create('interrupt-no-stream')
        ->addNode('review', InterruptManagerNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    app(GraphRegistry::class)->register('interrupt-no-stream', $graph);

    $manager = app(InterruptServiceInterface::class);

    $result = $manager->handle('exec-2', 'interrupt-no-stream', 'review', new State, null);

    $checkpoint = app(CheckpointManager::class)->load($result->checkpointId);

    expect($checkpoint)->not->toBeNull();
    expect(array_key_exists('stream_id', $checkpoint->metadata))->toBeFalse();
    expect(array_key_exists('last_seq', $checkpoint->metadata))->toBeFalse();
    expect($result->hasStreamContext)->toBeFalse();
});

it('forces checkpoint save on interrupt regardless of frequency', function () {
    config()->set('agenticgraph.checkpoint.frequency', 10);
    app()->forgetInstance(CheckpointManager::class);

    $graph = GraphBuilder::create('interrupt-forced-checkpoint')
        ->addNode('review', InterruptManagerNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    app(GraphRegistry::class)->register('interrupt-forced-checkpoint', $graph);

    $manager = app(InterruptServiceInterface::class);

    $result = $manager->handle('exec-3', 'interrupt-forced-checkpoint', 'review', new State, null);

    $checkpoint = app(CheckpointManager::class)->load($result->checkpointId);

    expect($checkpoint)->not->toBeNull();
});

it('invokes interrupt handler callbacks on handle and resume', function () {
    InterruptManagerHandler::reset();
    config()->set('agenticgraph.interrupts.handler', InterruptManagerHandler::class);
    config()->set('agenticgraph.interrupts.persistence_enabled', true);

    $graph = GraphBuilder::create('interrupt-handler-callbacks')
        ->addNode('review', InterruptManagerNode::class)
        ->setEntryPoint('review')
        ->addExitPoint('review')
        ->build();

    app(GraphRegistry::class)->register('interrupt-handler-callbacks', $graph);

    $manager = app(InterruptServiceInterface::class);

    $result = $manager->handle('exec-4', 'interrupt-handler-callbacks', 'review', new State, null);

    expect(InterruptManagerHandler::$handled)->toHaveCount(1);

    $record = $result->record->markResolving();

    $job = new ResumeExecutionJob($record->interruptId(), ['approved' => true]);
    $job->handle(app(InterruptServiceInterface::class), app(StreamResumption::class));

    expect(InterruptManagerHandler::$resolved)->toHaveCount(1);

    config()->set('agenticgraph.interrupts.handler', null);
});
