<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;

uses()->group('integration', 'phase1');

describe('phase 1 checkpoint fixes', function () {
    it('getVersions returns saved checkpoints via file storage driver', function () {
        $dir = sys_get_temp_dir().'/agenticgraph-phase1-'.uniqid();
        $storage = new FileStorage($dir);
        config(['agenticgraph.storage' => 'file']);

        $executionId = 'exec-file-'.uniqid();

        foreach (['step_a', 'step_b', 'step_c'] as $index => $node) {
            $checkpoint = new Checkpoint;
            $checkpoint->id = (string) Str::uuid();
            $checkpoint->graphName = 'phase1-graph';
            $checkpoint->executionId = $executionId;
            $checkpoint->currentNode = $node;
            $checkpoint->state = new State(['node' => $node]);
            $checkpoint->metadata = ['checkpoint_sequence' => $index + 1];
            $checkpoint->createdAt = new DateTimeImmutable;
            $storage->save($checkpoint);
        }

        $stateManager = new StateManager(
            app(DatabaseManager::class),
            $storage,
            app(Dispatcher::class)
        );

        $versions = $stateManager->getVersions($executionId);

        expect($versions)->toHaveCount(3)
            ->and(array_column($versions, 'node'))->toBe(['step_a', 'step_b', 'step_c']);

        foreach ($versions as $version) {
            $storage->delete($version['id']);
        }
    });

    it('loadLatestForExecution returns last checkpoint when created_at ties', function () {
        $storage = new DatabaseStorage;
        $executionId = 'exec-tie-'.uniqid();
        $sameTimestamp = new DateTimeImmutable('2026-01-01 12:00:00.000000');

        foreach (['step_a', 'step_b', 'step_c'] as $index => $node) {
            $checkpoint = new Checkpoint;
            $checkpoint->id = (string) Str::uuid();
            $checkpoint->graphName = 'phase1-graph';
            $checkpoint->executionId = $executionId;
            $checkpoint->currentNode = $node;
            $checkpoint->state = new State(['node' => $node]);
            $checkpoint->metadata = ['checkpoint_sequence' => $index + 1];
            $checkpoint->createdAt = $sameTimestamp;
            $storage->save($checkpoint);
        }

        $latest = $storage->loadLatestForExecution($executionId);

        expect($latest)->not->toBeNull()
            ->and($latest->currentNode)->toBe('step_c');

        $manager = app(CheckpointManager::class);
        $latestViaManager = $manager->getLatestForExecution($executionId);

        expect($latestViaManager?->currentNode)->toBe('step_c');

        $rows = app(DatabaseManager::class)
            ->table('agenticgraph_checkpoints')
            ->where('execution_id', $executionId)
            ->get();

        foreach ($rows as $row) {
            $storage->delete($row->id);
        }
    });

    it('checkpoint save load and resume work without tree fields', function () {
        $manager = app(CheckpointManager::class);
        $storage = app(StorageInterface::class);
        $executionId = 'exec-resume-'.uniqid();

        $checkpoint = new Checkpoint;
        $checkpoint->graphName = 'phase1-graph';
        $checkpoint->currentNode = 'resume_node';
        $checkpoint->state = new State(['counter' => 3]);
        $checkpoint->metadata = [];

        $id = $manager->save($executionId, $checkpoint);
        expect($id)->toBeString();

        $loaded = $storage->load($id);
        expect($loaded)->not->toBeNull()
            ->and($loaded->currentNode)->toBe('resume_node')
            ->and($loaded->state->get('counter'))->toBe(3);

        $rolledBack = app(StateManager::class)->rollback($executionId, $id);
        expect($rolledBack->get('counter'))->toBe(3);

        $storage->delete($id);
    });
});

describe('phase 1 event batching removal', function () {
    it('TokensUsed still dispatches as a plain Laravel event', function () {
        Event::fake([TokensUsed::class]);

        event(new TokensUsed(
            node: 'test-node',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
        ));

        Event::assertDispatched(TokensUsed::class, function ($event) {
            return $event->node === 'test-node'
                && $event->totalTokens === 15;
        });
    });
});
