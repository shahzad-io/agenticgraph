<?php

use Shahzad\AgenticGraph\Execution\CycleDetection;
use Shahzad\AgenticGraph\Execution\ExecutionContext;

beforeEach(function () {
    $this->executionId = 'test-exec-123';
    $this->startTime = microtime(true);
});

describe('construction', function () {

    it('creates with default values', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        expect($context->executionId)->toBe($this->executionId)
            ->and($context->startTime)->toBe($this->startTime)
            ->and($context->globalIterations)->toBe(0)
            ->and($context->pathStack)->toBe([])
            ->and($context->nodeVisitCounts)->toBe([])
            ->and($context->cycleCompletionCounts)->toBe([])
            ->and($context->nodeIndexMap)->toBe([]);
    });

    it('creates with custom values', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: 5,
            pathStack: ['A', 'B'],
            nodeVisitCounts: ['A' => 1, 'B' => 1],
            cycleCompletionCounts: ['A→B→A' => 2],
            nodeIndexMap: ['A' => [0], 'B' => [1]],
        );

        expect($context->globalIterations)->toBe(5)
            ->and($context->pathStack)->toBe(['A', 'B'])
            ->and($context->nodeVisitCounts)->toBe(['A' => 1, 'B' => 1])
            ->and($context->cycleCompletionCounts)->toBe(['A→B→A' => 2])
            ->and($context->nodeIndexMap)->toBe(['A' => [0], 'B' => [1]]);
    });

});

describe('incrementIteration', function () {

    it('returns new instance with incremented counter', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: 3,
        );

        $newContext = $context->incrementIteration();

        expect($newContext->globalIterations)->toBe(4)
            ->and($context->globalIterations)->toBe(3) // Original unchanged
            ->and($newContext)->not->toBe($context); // Different instance
    });

    it('preserves other properties', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: 3,
            pathStack: ['A', 'B'],
            nodeVisitCounts: ['A' => 1, 'B' => 1],
        );

        $newContext = $context->incrementIteration();

        expect($newContext->pathStack)->toBe(['A', 'B'])
            ->and($newContext->nodeVisitCounts)->toBe(['A' => 1, 'B' => 1]);
    });

});

describe('pushNode', function () {

    it('adds node to path stack', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        $newContext = $context->pushNode('A');

        expect($newContext->pathStack)->toBe(['A']);
    });

    it('tracks multiple nodes in order', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        $context = $context->pushNode('A');
        $context = $context->pushNode('B');
        $context = $context->pushNode('C');

        expect($context->pathStack)->toBe(['A', 'B', 'C']);
    });

    it('increments node visit count', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        $context = $context->pushNode('A');
        expect($context->nodeVisitCounts)->toBe(['A' => 1]);

        $context = $context->pushNode('A');
        expect($context->nodeVisitCounts)->toBe(['A' => 2]);
    });

    it('tracks visit counts per node', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        $context = $context->pushNode('A');
        $context = $context->pushNode('B');
        $context = $context->pushNode('A');

        expect($context->nodeVisitCounts)->toBe(['A' => 2, 'B' => 1]);
    });

    it('updates node index map', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        $context = $context->pushNode('A');
        expect($context->nodeIndexMap)->toBe(['A' => [0]]);

        $context = $context->pushNode('B');
        expect($context->nodeIndexMap)->toBe(['A' => [0], 'B' => [1]]);

        $context = $context->pushNode('A');
        expect($context->nodeIndexMap)->toBe(['A' => [0, 2], 'B' => [1]]);
    });

    it('preserves original context', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            pathStack: ['A'],
        );

        $newContext = $context->pushNode('B');

        expect($context->pathStack)->toBe(['A'])
            ->and($newContext->pathStack)->toBe(['A', 'B']);
    });

});

describe('detectAndRecordCycle', function () {

    it('returns null when node not previously visited', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        [$newContext, $cycle] = $context->detectAndRecordCycle('A');

        expect($cycle)->toBeNull()
            ->and($newContext)->toBe($context); // Same instance when no cycle
    });

    it('detects simple cycle A→A', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        $context = $context->pushNode('A');
        [$newContext, $cycle] = $context->detectAndRecordCycle('A');

        expect($cycle)->toBeInstanceOf(CycleDetection::class)
            ->and($cycle->cycleHash)->toBe('A→A')
            ->and($cycle->cyclePath)->toBe(['A', 'A'])
            ->and($cycle->completionCount)->toBe(1)
            ->and($cycle->cycleStartIndex)->toBe(0);
    });

    it('detects cycle A→B→A', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        $context = $context->pushNode('A');
        $context = $context->pushNode('B');
        [$newContext, $cycle] = $context->detectAndRecordCycle('A');

        expect($cycle->cycleHash)->toBe('A→B→A')
            ->and($cycle->cyclePath)->toBe(['A', 'B', 'A'])
            ->and($cycle->cycleStartIndex)->toBe(0);
    });

    it('detects nested cycle B→C→B', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        $context = $context->pushNode('A');
        $context = $context->pushNode('B');
        $context = $context->pushNode('C');
        [$newContext, $cycle] = $context->detectAndRecordCycle('B');

        expect($cycle->cycleHash)->toBe('B→C→B')
            ->and($cycle->cyclePath)->toBe(['B', 'C', 'B'])
            ->and($cycle->cycleStartIndex)->toBe(1);
    });

    it('increments completion count on repeat cycles', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        // First cycle completion: A→B→A
        $context = $context->pushNode('A');
        $context = $context->pushNode('B');
        [$context, $cycle1] = $context->detectAndRecordCycle('A');
        expect($cycle1->completionCount)->toBe(1);
        // Simulate actually going to A (updating the path)
        $context = $context->pushNode('A');

        // Second cycle completion: A→B→A (again)
        $context = $context->pushNode('B');
        [$context, $cycle2] = $context->detectAndRecordCycle('A');

        expect($cycle2->completionCount)->toBe(2)
            ->and($context->getCycleCompletionCount('A→B→A'))->toBe(2);
    });

    it('tracks multiple independent cycles', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        // First cycle: A→B→A
        $context = $context->pushNode('A');
        $context = $context->pushNode('B');
        [$context, $cycle1] = $context->detectAndRecordCycle('A');

        // Second cycle: B→C→B
        $context = $context->pushNode('B');
        $context = $context->pushNode('C');
        [$context, $cycle2] = $context->detectAndRecordCycle('B');

        expect($cycle2->cycleHash)->toBe('B→C→B')
            ->and($context->cycleCompletionCounts)->toHaveKey('A→B→A')
            ->and($context->cycleCompletionCounts)->toHaveKey('B→C→B')
            ->and($context->getCycleCompletionCount('A→B→A'))->toBe(1)
            ->and($context->getCycleCompletionCount('B→C→B'))->toBe(1);
    });

});

describe('serialization', function () {

    it('converts to array', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: 5,
            pathStack: ['A', 'B'],
            nodeVisitCounts: ['A' => 1, 'B' => 1],
            cycleCompletionCounts: ['A→B→A' => 2],
            nodeIndexMap: ['A' => [0], 'B' => [1]],
        );

        $array = $context->toArray();

        expect($array)->toHaveKey('execution_id', $this->executionId)
            ->and($array)->toHaveKey('start_time', $this->startTime)
            ->and($array)->toHaveKey('global_iterations', 5)
            ->and($array)->toHaveKey('path_stack', ['A', 'B'])
            ->and($array)->toHaveKey('node_visit_counts', ['A' => 1, 'B' => 1])
            ->and($array)->toHaveKey('cycle_completion_counts', ['A→B→A' => 2])
            ->and($array)->toHaveKey('node_index_map', ['A' => [0], 'B' => [1]]);
    });

    it('creates from array with defaults', function () {
        $array = [
            'execution_id' => $this->executionId,
            'global_iterations' => 10,
        ];

        $context = ExecutionContext::fromArray($array);

        expect($context->executionId)->toBe($this->executionId)
            ->and($context->globalIterations)->toBe(10)
            ->and($context->pathStack)->toBe([])
            ->and($context->startTime)->toBeFloat();
    });

    it('creates from array with override execution id', function () {
        $array = [
            'execution_id' => 'old-exec',
            'global_iterations' => 10,
        ];

        $context = ExecutionContext::fromArray($array, 'new-exec');

        expect($context->executionId)->toBe('new-exec');
    });

    it('round-trips through serialization', function () {
        $original = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: 5,
            pathStack: ['A', 'B', 'C'],
            nodeVisitCounts: ['A' => 2, 'B' => 1, 'C' => 1],
            cycleCompletionCounts: ['A→B→A' => 1],
            nodeIndexMap: ['A' => [0, 2], 'B' => [1], 'C' => [3]],
        );

        $array = $original->toArray();
        $restored = ExecutionContext::fromArray($array, $this->executionId);

        expect($restored->executionId)->toBe($original->executionId)
            ->and($restored->globalIterations)->toBe($original->globalIterations)
            ->and($restored->pathStack)->toBe($original->pathStack)
            ->and($restored->nodeVisitCounts)->toBe($original->nodeVisitCounts)
            ->and($restored->cycleCompletionCounts)->toBe($original->cycleCompletionCounts)
            ->and($restored->nodeIndexMap)->toBe($original->nodeIndexMap);
    });

});

describe('helper methods', function () {

    it('calculates elapsed time', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: microtime(true) - 1.5, // Started 1.5 seconds ago
        );

        $elapsed = $context->getElapsedTime();

        expect($elapsed)->toBeGreaterThan(1.0)
            ->and($elapsed)->toBeLessThan(2.0);
    });

    it('gets node visit count', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            nodeVisitCounts: ['A' => 3, 'B' => 1],
        );

        expect($context->getNodeVisitCount('A'))->toBe(3)
            ->and($context->getNodeVisitCount('B'))->toBe(1)
            ->and($context->getNodeVisitCount('C'))->toBe(0);
    });

    it('gets cycle completion count', function () {
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            cycleCompletionCounts: ['A→B→A' => 5, 'B→C→B' => 2],
        );

        expect($context->getCycleCompletionCount('A→B→A'))->toBe(5)
            ->and($context->getCycleCompletionCount('B→C→B'))->toBe(2)
            ->and($context->getCycleCompletionCount('X→Y→X'))->toBe(0);
    });

});
