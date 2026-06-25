<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Events\CheckpointCreated;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\State\State;

beforeEach(function () {
    $this->storage = Mockery::mock(StorageInterface::class);
    $this->db = Mockery::mock(DatabaseManager::class);
    $this->events = Mockery::mock(Dispatcher::class);
});

afterEach(function () {
    Mockery::close();
});

// Use the createCheckpoint function from tests/Support/Factories.php
// Note: createCheckpoint() is defined in tests/Support/Factories.php and loaded via tests/Pest.php
// The shared function has signature: createCheckpoint(string $graph = 'g', string $exec = 'e'): Checkpoint
// For tests that need custom node/state, we create checkpoints manually

describe('checkpoint creation', function () {

    it('saves checkpoint and returns id', function () {
        $manager = new CheckpointManager($this->storage, $this->db, $this->events);
        $checkpoint = createCheckpoint('test-graph', 'exec-123');
        $checkpoint->currentNode = 'node-a';
        $checkpoint->state = new State(['key' => 'value']);
        $executionId = 'exec-123';

        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->once()->andReturnUsing(function ($cp) {
            return $cp->id;
        });
        $this->events->shouldReceive('dispatch')->once()->with(Mockery::type(CheckpointCreated::class));

        $id = $manager->save($executionId, $checkpoint);

        expect($id)->toBeString();
        expect(Str::isUuid($id))->toBeTrue();
    });

    it('sets execution id on checkpoint', function () {
        $manager = new CheckpointManager($this->storage, $this->db, $this->events);
        $checkpoint = createCheckpoint('test-graph', 'exec-456');
        $executionId = 'exec-456';

        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->once();
        $this->events->shouldReceive('dispatch')->once();

        $manager->save($executionId, $checkpoint);

        expect($checkpoint->executionId)->toBe($executionId);
    });

    it('generates uuid if not set', function () {
        $manager = new CheckpointManager($this->storage, $this->db, $this->events);
        $checkpoint = createCheckpoint('test-graph', 'exec-123');

        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->once();
        $this->events->shouldReceive('dispatch')->once();

        $manager->save('exec-123', $checkpoint);

        expect($checkpoint->id)->toBeString();
        expect(Str::isUuid($checkpoint->id))->toBeTrue();
    });

    it('preserves existing checkpoint id', function () {
        $manager = new CheckpointManager($this->storage, $this->db, $this->events);
        $checkpoint = createCheckpoint('test-graph', 'exec-123');
        $existingId = 'custom-checkpoint-id';
        $checkpoint->id = $existingId;

        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->once();
        $this->events->shouldReceive('dispatch')->once();

        $manager->save('exec-123', $checkpoint);

        expect($checkpoint->id)->toBe($existingId);
    });

});

describe('checkpoint frequency', function () {

    it('skips checkpoint when frequency not met', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            checkpointFrequency: 3 // Every 3rd node
        );

        $checkpoint = createCheckpoint('test-graph', 'exec-123');
        $executionId = 'exec-123';

        // First node - should skip
        $id1 = $manager->save($executionId, $checkpoint);
        expect($id1)->toBeNull();

        // Second node - should skip
        $id2 = $manager->save($executionId, createCheckpoint('test-graph', $executionId));
        expect($id2)->toBeNull();

        // Third node - should save
        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->once()->andReturn('chk-3');
        $this->events->shouldReceive('dispatch')->once();

        $id3 = $manager->save($executionId, createCheckpoint('test-graph', $executionId));
        expect($id3)->toBe('chk-3');
    });

    it('saves interrupt checkpoints regardless of frequency', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            checkpointFrequency: 5
        );

        $executionId = 'exec-999';
        $checkpoint = createCheckpoint('test-graph', $executionId);

        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->once()->andReturn('chk-int');
        $this->events->shouldReceive('dispatch')->once();

        $id = $manager->saveInterrupt($executionId, $checkpoint);

        expect($id)->toBe('chk-int');
    });

    it('tracks node count per execution', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            checkpointFrequency: 2
        );

        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->andReturn('chk-id');
        $this->events->shouldReceive('dispatch');

        // Execution 1: nodes 1, 2, 3
        expect($manager->save('exec-1', createCheckpoint('test-graph', 'exec-1')))->toBeNull(); // node 1 - skip
        expect($manager->save('exec-1', createCheckpoint('test-graph', 'exec-1')))->toBe('chk-id'); // node 2 - save
        expect($manager->save('exec-1', createCheckpoint('test-graph', 'exec-1')))->toBeNull(); // node 3 - skip

        // Execution 2: nodes 1, 2
        expect($manager->save('exec-2', createCheckpoint('test-graph', 'exec-2')))->toBeNull(); // node 1 - skip
        expect($manager->save('exec-2', createCheckpoint('test-graph', 'exec-2')))->toBe('chk-id'); // node 2 - save
    });

    it('resets counter for execution', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            checkpointFrequency: 2
        );

        $executionId = 'exec-123';

        // First checkpoint
        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->andReturn('chk-1');
        $this->events->shouldReceive('dispatch');

        $manager->save($executionId, createCheckpoint('test-graph', $executionId)); // skip
        $manager->save($executionId, createCheckpoint('test-graph', $executionId)); // save

        expect($manager->getNodeCount($executionId))->toBe(2);

        // Reset counter
        $manager->resetCounter($executionId);

        expect($manager->getNodeCount($executionId))->toBe(0);
    });

    it('saves every node when frequency is 1', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            checkpointFrequency: 1
        );

        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->twice()->andReturn('chk-id');
        $this->events->shouldReceive('dispatch')->twice();

        expect($manager->save('exec-123', createCheckpoint('test-graph', 'exec-123')))->toBe('chk-id');
        expect($manager->save('exec-123', createCheckpoint('test-graph', 'exec-123')))->toBe('chk-id');
    });

});

describe('state size validation', function () {

    it('throws when state size exceeds maximum', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            maxStateSize: 100 // Very small limit
        );

        $checkpoint = createCheckpoint('test-graph', 'exec-123');
        $checkpoint->currentNode = 'node-a';
        $checkpoint->state = new State(['data' => str_repeat('x', 200)]);

        expect(fn () => $manager->save('exec-123', $checkpoint))
            ->toThrow(RuntimeException::class, 'exceeds maximum');
    });

    it('allows state within size limit', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            maxStateSize: 10000
        );

        $checkpoint = createCheckpoint('test-graph', 'exec-123');
        $checkpoint->currentNode = 'node-a';
        $checkpoint->state = new State(['data' => 'small']);

        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->once()->andReturn('chk-id');
        $this->events->shouldReceive('dispatch')->once();

        $id = $manager->save('exec-123', $checkpoint);

        expect($id)->toBe('chk-id');
    });

    it('uses configured max state size', function () {
        $manager = new CheckpointManager(
            $this->storage,
            $this->db,
            $this->events,
            maxStateSize: 5000
        );

        expect($manager->getMaxStateSize())->toBe(5000);
    });

});

describe('atomic operations', function () {

    it('wraps save and event in transaction', function () {
        $manager = new CheckpointManager($this->storage, $this->db, $this->events);
        $checkpoint = createCheckpoint('test-graph', 'exec-123');

        $transactionCalled = false;
        $this->db->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use (&$transactionCalled) {
            $transactionCalled = true;

            return $callback();
        });

        $saveCalled = false;
        $this->storage->shouldReceive('save')->once()->andReturnUsing(function () use (&$saveCalled) {
            $saveCalled = true;

            return 'chk-id';
        });

        $eventDispatched = false;
        $this->events->shouldReceive('dispatch')->once()->with(Mockery::type(CheckpointCreated::class))
            ->andReturnUsing(function () use (&$eventDispatched) {
                $eventDispatched = true;
            });

        $manager->save('exec-123', $checkpoint);

        expect($transactionCalled)->toBeTrue()
            ->and($saveCalled)->toBeTrue()
            ->and($eventDispatched)->toBeTrue();
    });

    it('dispatches checkpoint created event with correct data', function () {
        $manager = new CheckpointManager($this->storage, $this->db, $this->events);
        $checkpoint = createCheckpoint('test-graph', 'exec-789');
        $checkpoint->currentNode = 'node-x';
        $executionId = 'exec-789';

        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->andReturn('chk-uuid');

        $capturedEvent = null;
        $this->events->shouldReceive('dispatch')->once()->with(Mockery::on(function ($event) use (&$capturedEvent) {
            $capturedEvent = $event;

            return $event instanceof CheckpointCreated;
        }));

        $manager->save($executionId, $checkpoint);

        expect($capturedEvent)->toBeInstanceOf(CheckpointCreated::class)
            ->and($capturedEvent->executionId)->toBe($executionId)
            ->and($capturedEvent->checkpointId)->toBe('chk-uuid')
            ->and($capturedEvent->nodeName)->toBe('node-x');
    });

    it('saves without transaction when db not available', function () {
        $manager = new CheckpointManager($this->storage, null, $this->events);
        $checkpoint = createCheckpoint('test-graph', 'exec-123');

        $this->storage->shouldReceive('save')->once()->andReturn('chk-id');
        $this->events->shouldReceive('dispatch')->once();

        $id = $manager->save('exec-123', $checkpoint);

        expect($id)->toBe('chk-id');
    });

    it('saves without event when events not available', function () {
        $manager = new CheckpointManager($this->storage, $this->db, null);
        $checkpoint = createCheckpoint('test-graph', 'exec-123');

        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->storage->shouldReceive('save')->once()->andReturn('chk-id');

        $id = $manager->save('exec-123', $checkpoint);

        expect($id)->toBe('chk-id');
    });

});

describe('checkpoint operations', function () {

    it('loads checkpoint by id', function () {
        $manager = new CheckpointManager($this->storage);
        $expectedCheckpoint = createCheckpoint('test-graph', 'exec-123');

        $this->storage->shouldReceive('load')->with('chk-123')->andReturn($expectedCheckpoint);

        $checkpoint = $manager->load('chk-123');

        expect($checkpoint)->toBe($expectedCheckpoint);
    });

    it('returns null when checkpoint not found', function () {
        $manager = new CheckpointManager($this->storage);

        $this->storage->shouldReceive('load')->with('chk-missing')->andReturn(null);

        $checkpoint = $manager->load('chk-missing');

        expect($checkpoint)->toBeNull();
    });

    it('deletes checkpoint by id', function () {
        $manager = new CheckpointManager($this->storage);

        $this->storage->shouldReceive('delete')->with('chk-123')->andReturn(true);

        $result = $manager->delete('chk-123');

        expect($result)->toBeTrue();
    });

    it('lists checkpoints for graph', function () {
        $manager = new CheckpointManager($this->storage);
        $expectedList = [
            ['id' => 'chk-1', 'execution_id' => 'exec-1', 'current_node' => 'node-a', 'created_at' => '2024-01-01'],
        ];

        $this->storage->shouldReceive('list')->with('my-graph', 50, 0)->andReturn($expectedList);

        $list = $manager->list('my-graph');

        expect($list)->toBe($expectedList);
    });

    it('cleans up old checkpoints', function () {
        $manager = new CheckpointManager($this->storage);

        $this->storage->shouldReceive('cleanup')->with(30)->andReturn(5);

        $deleted = $manager->cleanup(30);

        expect($deleted)->toBe(5);
    });

});

describe('getters', function () {

    it('returns storage instance', function () {
        $manager = new CheckpointManager($this->storage);

        expect($manager->getStorage())->toBe($this->storage);
    });

    it('returns checkpoint frequency', function () {
        $manager = new CheckpointManager(
            $this->storage,
            null,
            null,
            checkpointFrequency: 5
        );

        expect($manager->getCheckpointFrequency())->toBe(5);
    });

    it('returns max state size', function () {
        $manager = new CheckpointManager(
            $this->storage,
            null,
            null,
            maxStateSize: 20971520
        );

        expect($manager->getMaxStateSize())->toBe(20971520);
    });

});
