<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Storage\RedisStorage;

uses()->group('integration');

beforeEach(function () {
    // These tests require database setup
    $this->db = app(DatabaseManager::class);
    $this->storage = app(StorageInterface::class);
    $this->events = app(Dispatcher::class);
    $this->stateManager = app(StateManager::class);
});

describe('state manager integration', function () {

    it('resolves from service container with correct dependencies', function () {
        $manager = app(StateManager::class);

        expect($manager)->toBeInstanceOf(StateManager::class)
            ->and($manager->getDatabaseManager())->toBeInstanceOf(DatabaseManager::class)
            ->and($manager->getStorage())->toBeInstanceOf(StorageInterface::class)
            ->and($manager->getEventDispatcher())->toBeInstanceOf(Dispatcher::class);
    });

    it('is registered as singleton', function () {
        $manager1 = app(StateManager::class);
        $manager2 = app(StateManager::class);

        expect($manager1)->toBe($manager2);
    });

    it('diffs states correctly', function () {
        $old = new State(['a' => 1, 'b' => 2, 'c' => 3]);
        $new = new State(['b' => 20, 'c' => 3, 'd' => 4]);

        $diff = $this->stateManager->diff($old, $new);

        expect($diff['added'])->toBe(['d' => 4])
            ->and($diff['removed'])->toBe(['a' => 1])
            ->and($diff['modified'])->toBe(['b' => ['from' => 2, 'to' => 20]]);
    });

    it('encrypts and decrypts state roundtrip', function () {
        $original = new State(['secret' => 'my-secret-value', 'number' => 42]);

        $encrypted = $this->stateManager->encrypt($original);
        $decrypted = $this->stateManager->decrypt($encrypted);

        expect($decrypted->toArray())->toBe($original->toArray());
    });

    it('compresses and decompresses state roundtrip', function () {
        $original = new State(['data' => str_repeat('large payload ', 1000)]);

        $compressed = $this->stateManager->compress($original);
        $decompressed = $this->stateManager->decompress($compressed);

        expect($decompressed->toArray())->toBe($original->toArray())
            ->and(strlen($compressed))->toBeLessThan(strlen(json_encode($original->toArray())));
    });

    it('merges multiple states', function () {
        $states = [
            new State(['a' => 1]),
            new State(['b' => 2]),
            new State(['c' => 3]),
        ];

        $merged = $this->stateManager->mergeAll($states);

        expect($merged->get('a'))->toBe(1)
            ->and($merged->get('b'))->toBe(2)
            ->and($merged->get('c'))->toBe(3);
    });

    it('gets versions for execution from database', function () {
        // This test requires actual database records
        // Skip if no database available
        if (! app()->bound(DatabaseManager::class)) {
            $this->markTestSkipped('Database not available');
        }

        $executionId = 'test-exec-'.uniqid();

        // Create test checkpoints directly in database
        $now = now();
        $this->db->table('agenticgraph_checkpoints')->insert([
            [
                'id' => 'chk-1',
                'graph_name' => 'test-graph',
                'execution_id' => $executionId,
                'current_node' => 'node-a',
                'state' => json_encode(['key' => 'value1']),
                'completed_nodes' => json_encode([]),
                'pending_nodes' => json_encode([]),
                'metadata' => json_encode([]),
                'created_at' => $now,
            ],
            [
                'id' => 'chk-2',
                'graph_name' => 'test-graph',
                'execution_id' => $executionId,
                'current_node' => 'node-b',
                'state' => json_encode(['key' => 'value2']),
                'completed_nodes' => json_encode(['node-a']),
                'pending_nodes' => json_encode([]),
                'metadata' => json_encode([]),
                'created_at' => $now->copy()->addMinute(),
            ],
        ]);

        $versions = $this->stateManager->getVersions($executionId);

        expect($versions)->toHaveCount(2)
            ->and($versions[0]['node'])->toBe('node-a')
            ->and($versions[1]['node'])->toBe('node-b');

        // Cleanup
        $this->db->table('agenticgraph_checkpoints')->where('execution_id', $executionId)->delete();
    });

    it('rolls back to checkpoint', function () {
        $checkpointId = 'test-chk-'.uniqid();
        $executionId = 'test-exec-'.uniqid();

        // Create checkpoint in storage
        $checkpoint = new Checkpoint;
        $checkpoint->id = $checkpointId;
        $checkpoint->executionId = $executionId;
        $checkpoint->graphName = 'test-graph';
        $checkpoint->currentNode = 'node-a';
        $checkpoint->state = new State(['rolled' => 'back']);
        $checkpoint->completedNodes = [];
        $checkpoint->pendingNodes = [];
        $checkpoint->metadata = [];
        $checkpoint->createdAt = new DateTimeImmutable;

        $this->storage->save($checkpoint);

        $state = $this->stateManager->rollback($executionId, $checkpointId);

        expect($state->get('rolled'))->toBe('back');

        // Cleanup
        $this->storage->delete($checkpointId);
    });

    it('throws when checkpoint not found', function () {
        expect(fn () => $this->stateManager->rollback('exec-123', 'non-existent'))
            ->toThrow(RuntimeException::class, 'Checkpoint not found');
    });

    it('throws when checkpoint belongs to different execution', function () {
        $checkpointId = 'test-chk-'.uniqid();
        $executionId = 'correct-exec-'.uniqid();
        $wrongExecutionId = 'wrong-exec-'.uniqid();

        // Create checkpoint
        $checkpoint = new Checkpoint;
        $checkpoint->id = $checkpointId;
        $checkpoint->executionId = $executionId;
        $checkpoint->graphName = 'test-graph';
        $checkpoint->currentNode = 'node-a';
        $checkpoint->state = new State(['data' => 'test']);
        $checkpoint->completedNodes = [];
        $checkpoint->pendingNodes = [];
        $checkpoint->metadata = [];
        $checkpoint->createdAt = new DateTimeImmutable;

        $this->storage->save($checkpoint);

        try {
            expect(fn () => $this->stateManager->rollback($wrongExecutionId, $checkpointId))
                ->toThrow(RuntimeException::class, 'does not belong to execution');
        } finally {
            // Cleanup
            $this->storage->delete($checkpointId);
        }
    });

});

describe('storage integration', function () {

    it('uses configured storage driver', function () {
        $storage = app(StorageInterface::class);
        $configuredDriver = config('agenticgraph.storage', 'database');

        $expectedClass = match ($configuredDriver) {
            'redis' => RedisStorage::class,
            'file' => FileStorage::class,
            default => DatabaseStorage::class,
        };

        expect($storage)->toBeInstanceOf($expectedClass);
    });

});
