<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\CheckpointCreated;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\State\State;

uses()->group('integration');

beforeEach(function () {
    $this->storage = app(StorageInterface::class);
    $this->db = app(DatabaseManager::class);

    // Fake events and queue BEFORE creating the manager
    Event::fake([CheckpointCreated::class]);
    Queue::fake();

    // Get the faked event dispatcher
    $this->events = Event::getFacadeRoot();

    // Use real dependencies for integration test
    $this->manager = new CheckpointManager(
        $this->storage,
        $this->db,
        $this->events,
        checkpointFrequency: 1,
        maxStateSize: 10485760
    );
});

afterEach(function () {
    // Cleanup any created checkpoints
    if (isset($this->createdCheckpointIds)) {
        foreach ($this->createdCheckpointIds as $id) {
            try {
                $this->storage->delete($id);
            } catch (Throwable $e) {
                // Ignore cleanup errors
            }
        }
    }
});

function createTestCheckpoint(string $node = 'test-node', array $stateData = []): Checkpoint
{
    $checkpoint = new Checkpoint;
    $checkpoint->graphName = 'integration-test-graph';
    $checkpoint->currentNode = $node;
    $checkpoint->state = new State($stateData);
    $checkpoint->metadata = ['test' => true];

    return $checkpoint;
}

describe('atomic checkpoint creation', function () {

    it('creates checkpoint and dispatches event atomically', function () {
        $executionId = 'integration-exec-'.uniqid();
        $checkpoint = createTestCheckpoint('node-a', ['step' => 1]);

        $id = $this->manager->save($executionId, $checkpoint);

        // Track for cleanup
        $this->createdCheckpointIds = [$id];

        // Assert checkpoint was saved
        expect($id)->toBeString();

        // Assert event was dispatched
        Event::assertDispatched(CheckpointCreated::class, function ($event) use ($executionId, $id) {
            return $event->executionId === $executionId
                && $event->checkpointId === $id
                && $event->nodeName === 'node-a';
        });
    });

    it('loads saved checkpoint correctly', function () {
        $executionId = 'integration-exec-'.uniqid();
        $checkpoint = createTestCheckpoint('node-b', ['data' => 'test-value']);

        $id = $this->manager->save($executionId, $checkpoint);
        $this->createdCheckpointIds = [$id];

        // Load and verify
        $loaded = $this->manager->load($id);

        expect($loaded)->not->toBeNull()
            ->and($loaded->id)->toBe($id)
            ->and($loaded->executionId)->toBe($executionId)
            ->and($loaded->currentNode)->toBe('node-b')
            ->and($loaded->state->get('data'))->toBe('test-value');
    });

    it('respects checkpoint frequency configuration', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            checkpointFrequency: 3, // Every 3rd node
            maxStateSize: 10485760
        );

        $executionId = 'integration-exec-'.uniqid();
        $this->createdCheckpointIds = [];

        // Node 1 - should skip
        $id1 = $manager->save($executionId, createTestCheckpoint('node-1'));
        expect($id1)->toBeNull();

        // Node 2 - should skip
        $id2 = $manager->save($executionId, createTestCheckpoint('node-2'));
        expect($id2)->toBeNull();

        // Node 3 - should save
        $id3 = $manager->save($executionId, createTestCheckpoint('node-3'));
        expect($id3)->toBeString();
        $this->createdCheckpointIds[] = $id3;

        // Verify only one checkpoint created
        Event::assertDispatched(CheckpointCreated::class, 1);
    });

    it('enforces max state size limit', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            checkpointFrequency: 1,
            maxStateSize: 100 // Very small limit
        );

        $executionId = 'integration-exec-'.uniqid();
        $checkpoint = createTestCheckpoint('node-large', ['data' => str_repeat('x', 200)]);

        expect(fn () => $manager->save($executionId, $checkpoint))
            ->toThrow(RuntimeException::class, 'exceeds maximum');

        // Verify no event was dispatched
        Event::assertNotDispatched(CheckpointCreated::class);
    });

    it('handles concurrent execution ids independently', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            checkpointFrequency: 2,
            maxStateSize: 10485760
        );

        $exec1 = 'exec-1-'.uniqid();
        $exec2 = 'exec-2-'.uniqid();
        $this->createdCheckpointIds = [];

        // Both executions at node 1 - both skip
        expect($manager->save($exec1, createTestCheckpoint('node-1')))->toBeNull();
        expect($manager->save($exec2, createTestCheckpoint('node-1')))->toBeNull();

        // Both executions at node 2 - both save
        $id1 = $manager->save($exec1, createTestCheckpoint('node-2'));
        $id2 = $manager->save($exec2, createTestCheckpoint('node-2'));

        expect($id1)->toBeString();
        expect($id2)->toBeString();
        expect($id1)->not->toBe($id2);

        $this->createdCheckpointIds = [$id1, $id2];

        // Verify two events dispatched
        Event::assertDispatched(CheckpointCreated::class, 2);
    });

    it('deletes checkpoint successfully', function () {
        $executionId = 'integration-exec-'.uniqid();
        $checkpoint = createTestCheckpoint('node-delete', ['temp' => true]);

        $id = $this->manager->save($executionId, $checkpoint);

        // Verify exists
        expect($this->manager->load($id))->not->toBeNull();

        // Delete
        $result = $this->manager->delete($id);

        expect($result)->toBeTrue()
            ->and($this->manager->load($id))->toBeNull();
    });

    it('lists checkpoints for graph', function () {
        $executionId = 'integration-exec-'.uniqid();
        $graphName = 'integration-test-graph';
        $this->createdCheckpointIds = [];

        // Create multiple checkpoints
        for ($i = 1; $i <= 3; $i++) {
            $checkpoint = createTestCheckpoint("node-{$i}", ['index' => $i]);
            $id = $this->manager->save($executionId, $checkpoint);
            $this->createdCheckpointIds[] = $id;
        }

        $list = $this->manager->list($graphName, limit: 10);

        // Should find our checkpoints
        $ourCheckpoints = array_filter($list, fn ($c) => $c['execution_id'] === $executionId);
        expect($ourCheckpoints)->toHaveCount(3);
    });

    it('cleans up old checkpoints', function () {
        // This test assumes cleanup works with storage
        $deleted = $this->manager->cleanup(olderThanDays: 0);

        // Should return number of deleted records (may be 0 in fresh test)
        expect($deleted)->toBeInt();
    });

});

describe('checkpoint event data', function () {

    it('dispatches event with correct checkpoint data', function () {
        $executionId = 'integration-exec-'.uniqid();
        $checkpoint = createTestCheckpoint('specific-node', ['key' => 'value']);

        $id = $this->manager->save($executionId, $checkpoint);
        $this->createdCheckpointIds = [$id];

        Event::assertDispatched(CheckpointCreated::class, function ($event) use ($executionId, $id) {
            return $event->executionId === $executionId
                && $event->checkpointId === $id
                && $event->nodeName === 'specific-node'
                && $event->createdAt instanceof DateTimeImmutable;
        });
    });

});
