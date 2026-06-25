<?php

use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\LoopContext;

it('exposes visit count and visited state from execution context', function () {
    $context = new ExecutionContext(
        executionId: 'exec-1',
        startTime: microtime(true) - 2.0,
        globalIterations: 3,
        pathStack: ['writer', 'critic', 'writer'],
        nodeVisitCounts: ['writer' => 2, 'critic' => 1],
    );

    $loop = LoopContext::from($context);

    expect($loop->visitCount('writer'))->toBe(2)
        ->and($loop->visitCount('critic'))->toBe(1)
        ->and($loop->visitCount('editor'))->toBe(0)
        ->and($loop->hasVisited('writer'))->toBeTrue()
        ->and($loop->hasVisited('editor'))->toBeFalse();
});

it('exposes global iterations and elapsed seconds', function () {
    $context = new ExecutionContext(
        executionId: 'exec-2',
        startTime: microtime(true) - 1.5,
        globalIterations: 7,
    );

    $loop = LoopContext::from($context);

    expect($loop->globalIterations())->toBe(7)
        ->and($loop->elapsedSeconds())->toBeGreaterThan(1.0)
        ->and($loop->elapsedSeconds())->toBeLessThan(3.0);
});

it('exposes path depth and recent path', function () {
    $context = new ExecutionContext(
        executionId: 'exec-3',
        startTime: microtime(true),
        pathStack: ['a', 'b', 'c', 'd', 'e'],
    );

    $loop = LoopContext::from($context);

    expect($loop->pathDepth())->toBe(5)
        ->and($loop->recentPath())->toBe(['a', 'b', 'c', 'd', 'e'])
        ->and($loop->recentPath(2))->toBe(['d', 'e'])
        ->and($loop->recentPath(0))->toBe([]);
});

it('builds visit count subsets and unvisited peers', function () {
    $context = new ExecutionContext(
        executionId: 'exec-4',
        startTime: microtime(true),
        nodeVisitCounts: ['writer' => 3, 'critic' => 3],
    );

    $loop = LoopContext::from($context);
    $peers = ['writer', 'critic', 'fact_checker', 'editor'];

    expect($loop->visitCountsFor($peers))->toBe([
        'writer' => 3,
        'critic' => 3,
        'fact_checker' => 0,
        'editor' => 0,
    ])->and($loop->unvisitedAmong($peers))->toBe(['fact_checker', 'editor']);
});

it('returns the most visited node among a set', function () {
    $context = new ExecutionContext(
        executionId: 'exec-5',
        startTime: microtime(true),
        nodeVisitCounts: ['writer' => 3, 'critic' => 5, 'editor' => 1],
    );

    $loop = LoopContext::from($context);

    expect($loop->mostVisitedAmong(['writer', 'critic', 'editor']))->toBe('critic')
        ->and($loop->mostVisitedAmong(['fact_checker']))->toBeNull()
        ->and($loop->mostVisitedAmong([]))->toBeNull();
});

it('does not expose raw execution context internals on the public surface', function () {
    $context = new ExecutionContext(
        executionId: 'hidden-id',
        startTime: microtime(true),
        nodeIndexMap: ['a' => [0]],
        cycleCompletionCounts: ['a→b→a' => 2],
    );

    $loop = LoopContext::from($context);
    $publicMethods = array_map(
        fn (\ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($loop))->getMethods(ReflectionMethod::IS_PUBLIC)
    );

    expect($publicMethods)->not->toContain('getNodeVisitCount')
        ->and($publicMethods)->not->toContain('detectAndRecordCycle')
        ->and((new ReflectionClass($loop))->getProperty('context')->isPrivate())->toBeTrue();
});
