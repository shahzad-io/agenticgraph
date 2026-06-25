<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\State\Facades\StateManager as StateManagerFacade;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;

beforeEach(function () {
    $this->db = Mockery::mock(DatabaseManager::class);
    $this->storage = Mockery::mock(StorageInterface::class);
    $this->events = Mockery::mock(Dispatcher::class);
    $this->stateManager = new StateManager($this->db, $this->storage, $this->events);
});

afterEach(function () {
    Mockery::close();
});

// Instance Method Tests

describe('instance methods', function () {

    it('diffs states', function () {
        $a = new State(['x' => 1, 'y' => 2]);
        $b = new State(['y' => 3, 'z' => 4]);
        $diff = $this->stateManager->diff($a, $b);

        expect($diff['added'])->toHaveKey('z')
            ->and($diff['removed'])->toHaveKey('x')
            ->and($diff['modified'])->toHaveKey('y');
    });

    it('encrypts and decrypts state', function () {
        $state = new State(['secret' => 'value']);
        $enc = $this->stateManager->encrypt($state);
        $dec = $this->stateManager->decrypt($enc);

        expect($dec->get('secret'))->toBe('value');
    });

    it('compresses and decompresses state', function () {
        $state = new State(['payload' => str_repeat('a', 100)]);
        $compressed = $this->stateManager->compress($state);
        $decompressed = $this->stateManager->decompress($compressed);

        expect($decompressed->get('payload'))->toBe(str_repeat('a', 100));
    });

    it('gets versions from database', function () {
        $executionId = 'exec-123';
        $checkpoints = [
            (object) ['id' => 'chk-1', 'current_node' => 'node-a', 'created_at' => '2024-01-01 10:00:00'],
            (object) ['id' => 'chk-2', 'current_node' => 'node-b', 'created_at' => '2024-01-01 10:01:00'],
        ];

        $query = Mockery::mock();
        $query->shouldReceive('where')->with('execution_id', $executionId)->andReturnSelf();
        $query->shouldReceive('orderBy')->with('created_at')->andReturnSelf();
        $query->shouldReceive('get')->andReturn(collect($checkpoints));

        $this->db->shouldReceive('table')->with('agenticgraph_checkpoints')->andReturn($query);

        $versions = $this->stateManager->getVersions($executionId);

        expect($versions)->toHaveCount(2)
            ->and($versions[0]['id'])->toBe('chk-1')
            ->and($versions[0]['node'])->toBe('node-a');
    });

    it('rolls back to checkpoint', function () {
        $executionId = 'exec-123';
        $checkpointId = 'chk-1';
        $expectedState = new State(['key' => 'value']);

        $checkpoint = new Checkpoint;
        $checkpoint->executionId = $executionId;
        $checkpoint->state = $expectedState;

        $this->storage->shouldReceive('load')->with($checkpointId)->andReturn($checkpoint);

        $state = $this->stateManager->rollback($executionId, $checkpointId);

        expect($state)->toBe($expectedState);
    });

    it('throws when checkpoint not found during rollback', function () {
        $this->storage->shouldReceive('load')->with('chk-missing')->andReturn(null);

        expect(fn () => $this->stateManager->rollback('exec-123', 'chk-missing'))
            ->toThrow(RuntimeException::class, 'Checkpoint not found: chk-missing');
    });

    it('throws when checkpoint belongs to different execution', function () {
        $checkpoint = new Checkpoint;
        $checkpoint->executionId = 'different-exec';

        $this->storage->shouldReceive('load')->with('chk-1')->andReturn($checkpoint);

        expect(fn () => $this->stateManager->rollback('exec-123', 'chk-1'))
            ->toThrow(RuntimeException::class, 'does not belong to execution');
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

    it('returns empty state when merging empty array', function () {
        $merged = $this->stateManager->mergeAll([]);

        expect($merged->toArray())->toBe([]);
    });

    it('returns database manager instance', function () {
        expect($this->stateManager->getDatabaseManager())->toBe($this->db);
    });

    it('returns storage instance', function () {
        expect($this->stateManager->getStorage())->toBe($this->storage);
    });

    it('returns event dispatcher instance', function () {
        expect($this->stateManager->getEventDispatcher())->toBe($this->events);
    });

});

// Facade/Static Proxy Tests (Backward Compatibility)

describe('facade static proxies', function () {

    beforeEach(function () {
        // Bind the mock to the container for facade resolution
        $this->db = Mockery::mock(DatabaseManager::class);
        $this->storage = Mockery::mock(StorageInterface::class);
        $this->events = Mockery::mock(Dispatcher::class);

        $stateManager = new StateManager($this->db, $this->storage, $this->events);
        app()->instance(StateManager::class, $stateManager);
    });

    it('diffs states via facade', function () {
        $a = new State(['x' => 1, 'y' => 2]);
        $b = new State(['y' => 3, 'z' => 4]);
        $diff = StateManagerFacade::diff($a, $b);

        expect($diff['added'])->toHaveKey('z')
            ->and($diff['removed'])->toHaveKey('x')
            ->and($diff['modified'])->toHaveKey('y');
    });

    it('encrypts and decrypts state via facade', function () {
        $state = new State(['secret' => 'value']);
        $enc = StateManagerFacade::encrypt($state);
        $dec = StateManagerFacade::decrypt($enc);

        expect($dec->get('secret'))->toBe('value');
    });

    it('compresses and decompresses state via facade', function () {
        $state = new State(['payload' => str_repeat('a', 100)]);
        $compressed = StateManagerFacade::compress($state);
        $decompressed = StateManagerFacade::decompress($compressed);

        expect($decompressed->get('payload'))->toBe(str_repeat('a', 100));
    });

    it('merges states via facade', function () {
        $states = [
            new State(['a' => 1]),
            new State(['b' => 2]),
        ];

        $merged = StateManagerFacade::mergeAll($states);

        expect($merged->get('a'))->toBe(1)
            ->and($merged->get('b'))->toBe(2);
    });

});

// Dependency Injection and Mockability Tests

describe('dependency injection', function () {

    it('can be mocked for unit testing', function () {
        $mock = Mockery::mock(StateManager::class);
        $mock->shouldReceive('diff')->andReturn(['added' => [], 'modified' => [], 'removed' => []]);
        $mock->shouldReceive('encrypt')->andReturn('encrypted');

        app()->instance(StateManager::class, $mock);

        $result = app(StateManager::class)->diff(new State, new State);

        expect($result)->toBe(['added' => [], 'modified' => [], 'removed' => []]);
    });

    it('resolves from container with dependencies', function () {
        $resolved = app(StateManager::class);

        expect($resolved)->toBeInstanceOf(StateManager::class)
            ->and($resolved->getDatabaseManager())->toBeInstanceOf(DatabaseManager::class)
            ->and($resolved->getStorage())->toBeInstanceOf(StorageInterface::class)
            ->and($resolved->getEventDispatcher())->toBeInstanceOf(Dispatcher::class);
    });

});
