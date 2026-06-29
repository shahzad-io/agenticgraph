<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Execution\CycleDetection;
use Shahzad\AgenticGraph\Execution\ExecutionContext;

/**
 * Simulates the coordinator's detect-before-push cycle order.
 */
function simulateCoordinatorVisit(ExecutionContext $context, string $node): array
{
    [$context, $cycle] = $context->detectAndRecordCycle($node);
    $context = $context->pushNode($node);

    return [$context, $cycle];
}

it('records no cycles when visiting a linear chain once each', function (): void {
    $context = new ExecutionContext(
        executionId: 'exec-linear',
        startTime: microtime(true),
    );

    foreach (['analyze', 'generate_meta', 'keywords'] as $node) {
        [$context, $cycle] = simulateCoordinatorVisit($context, $node);
        expect($cycle)->toBeNull();
    }

    expect($context->cycleCompletionCounts)->toBeEmpty()
        ->and($context->pathStack)->toBe(['analyze', 'generate_meta', 'keywords']);
});

it('records a multi-hop cycle when a node is revisited via a back-edge', function (): void {
    $context = new ExecutionContext(
        executionId: 'exec-loop',
        startTime: microtime(true),
    );

    [$context, $cycle] = simulateCoordinatorVisit($context, 'writer');
    expect($cycle)->toBeNull();

    [$context, $cycle] = simulateCoordinatorVisit($context, 'critic');
    expect($cycle)->toBeNull();

    [$context, $cycle] = simulateCoordinatorVisit($context, 'writer');
    expect($cycle)->toBeInstanceOf(CycleDetection::class)
        ->and($cycle->cycleHash)->toBe('writer→critic→writer')
        ->and($cycle->completionCount)->toBe(1);

    [$context, $cycle] = simulateCoordinatorVisit($context, 'critic');
    expect($cycle)->toBeInstanceOf(CycleDetection::class)
        ->and($cycle->cycleHash)->toBe('critic→writer→critic')
        ->and($context->getCycleCompletionCount('writer→critic→writer'))->toBe(1)
        ->and($context->getCycleCompletionCount('critic→writer→critic'))->toBe(1);
});

it('increments cycle completion count on repeated back-edges', function (): void {
    $context = new ExecutionContext(
        executionId: 'exec-loop-repeat',
        startTime: microtime(true),
    );

    foreach (['writer', 'critic'] as $node) {
        [$context] = simulateCoordinatorVisit($context, $node);
    }

    [$context, $cycle1] = simulateCoordinatorVisit($context, 'writer');
    expect($cycle1?->cycleHash)->toBe('writer→critic→writer')
        ->and($cycle1?->completionCount)->toBe(1);

    [$context] = simulateCoordinatorVisit($context, 'critic');
    [$context, $cycle2] = simulateCoordinatorVisit($context, 'writer');
    expect($cycle2?->cycleHash)->toBe('writer→critic→writer')
        ->and($cycle2?->completionCount)->toBe(2);
});
