<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;

beforeEach(function () {
    $this->stateManager = new StateManager(
        Mockery::mock(DatabaseManager::class),
        Mockery::mock(StorageInterface::class),
        Mockery::mock(Dispatcher::class),
    );
});

afterEach(function () {
    Mockery::close();
});

it('detects a collision when branches write the same key with different values', function () {
    $base = new State(['topic' => 'demo']);

    $collisions = $this->stateManager->detectParallelMergeCollisions(
        $base,
        ['branch_a', 'branch_b'],
        [
            new State(['topic' => 'demo', 'result' => 'alpha']),
            new State(['topic' => 'demo', 'result' => 'beta']),
        ],
    );

    expect($collisions)->toHaveCount(1)
        ->and($collisions[0]['key'])->toBe('result')
        ->and($collisions[0]['branch_values'])->toBe([
            'branch_a' => 'alpha',
            'branch_b' => 'beta',
        ]);
});

it('does not detect a collision when branches write the same key with the same value', function () {
    $base = new State(['topic' => 'demo']);

    $collisions = $this->stateManager->detectParallelMergeCollisions(
        $base,
        ['branch_a', 'branch_b'],
        [
            new State(['topic' => 'demo', 'result' => 'same']),
            new State(['topic' => 'demo', 'result' => 'same']),
        ],
    );

    expect($collisions)->toBe([]);
});

it('does not detect a collision when branches write unique keys', function () {
    $base = new State(['topic' => 'demo']);

    $collisions = $this->stateManager->detectParallelMergeCollisions(
        $base,
        ['technical', 'market', 'risks'],
        [
            new State(['topic' => 'demo', 'technical_draft' => 'tech']),
            new State(['topic' => 'demo', 'market_draft' => 'market']),
            new State(['topic' => 'demo', 'risks_draft' => 'risks']),
        ],
    );

    expect($collisions)->toBe([]);
});

it('does not detect a collision for pre-existing keys left unchanged by branches', function () {
    $base = new State(['topic' => 'demo', 'existing' => 'unchanged']);

    $collisions = $this->stateManager->detectParallelMergeCollisions(
        $base,
        ['branch_a', 'branch_b'],
        [
            new State(['topic' => 'demo', 'existing' => 'unchanged', 'branch_a' => 1]),
            new State(['topic' => 'demo', 'existing' => 'unchanged', 'branch_b' => 2]),
        ],
    );

    expect($collisions)->toBe([]);
});

it('logs a warning for detected collisions without changing merge output', function () {
    config()->set('agenticgraph.logging.enabled', true);

    $base = new State(['topic' => 'demo']);
    $branchResults = [
        new State(['topic' => 'demo', 'result' => 'alpha']),
        new State(['topic' => 'demo', 'result' => 'beta']),
    ];

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->once()
        ->with(
            Mockery::type('string'),
            Mockery::on(function (array $context) {
                return ($context['key'] ?? null) === 'result'
                    && ! array_key_exists('branch_values', $context)
                    && ($context['kept_branch'] ?? null) === 'branch_b';
            })
        );

    $this->stateManager->warnParallelMergeCollisions(
        $logger,
        $base,
        ['branch_a', 'branch_b'],
        $branchResults,
    );

    $merged = $this->stateManager->mergeAll($branchResults);

    expect($merged->get('result'))->toBe('beta');
});
