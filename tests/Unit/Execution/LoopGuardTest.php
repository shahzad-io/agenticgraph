<?php

use Shahzad\AgenticGraph\Execution\CycleDetection;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\LoopGuard;
use Shahzad\AgenticGraph\Execution\LoopLimitExceededException;

beforeEach(function () {
    $this->executionId = 'test-exec-123';
    $this->startTime = microtime(true);
});

describe('construction', function () {

    it('creates with enabled=false as no-op', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 100,
            maxCycleIterations: 10,
            timeoutSeconds: 30,
            enabled: false,
        );

        expect($guard->isEnabled())->toBeFalse();
    });

    it('creates with enabled=true', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 100,
            maxCycleIterations: 10,
            timeoutSeconds: 30,
            enabled: true,
        );

        expect($guard->isEnabled())->toBeTrue();
    });

});

describe('disabled guard', function () {

    it('does not throw when disabled regardless of context', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 1,
            maxCycleIterations: 1,
            timeoutSeconds: 1,
            enabled: false,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime - 100, // Way past timeout
            globalIterations: 999,
        );

        expect(fn () => $guard->check($context))->not->toThrow(\Throwable::class);
    });

});

describe('global iteration limit', function () {

    it('does not throw when under limit', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 100,
            maxCycleIterations: null,
            timeoutSeconds: null,
            enabled: true,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: 99,
        );

        expect(fn () => $guard->check($context))->not->toThrow(\Throwable::class);
    });

    it('throws when at limit', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 100,
            maxCycleIterations: null,
            timeoutSeconds: null,
            enabled: true,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: 100,
            pathStack: ['A', 'B', 'C'],
        );

        expect(fn () => $guard->check($context))
            ->toThrow(LoopLimitExceededException::class, 'exceeded maximum iterations');
    });

    it('throws when over limit', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 100,
            maxCycleIterations: null,
            timeoutSeconds: null,
            enabled: true,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: 150,
        );

        expect(fn () => $guard->check($context))
            ->toThrow(LoopLimitExceededException::class);
    });

    it('includes correct context in exception', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 100,
            maxCycleIterations: null,
            timeoutSeconds: null,
            enabled: true,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: 100,
            pathStack: ['A', 'B', 'C'],
        );

        try {
            $guard->check($context);
        } catch (LoopLimitExceededException $e) {
            expect($e->limitType)->toBe('global')
                ->and($e->limitValue)->toBe(100)
                ->and($e->currentValue)->toBe(100)
                ->and($e->pathStack)->toBe(['A', 'B', 'C'])
                ->and($e->cycleHash)->toBeNull();
        }
    });

});

describe('cycle iteration limit', function () {

    it('does not check when maxCycleIterations is null', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 1000,
            maxCycleIterations: null,
            timeoutSeconds: null,
            enabled: true,
        );

        $cycle = new CycleDetection(
            cycleHash: 'A→B→A',
            cyclePath: ['A', 'B', 'A'],
            completionCount: 999,
            cycleStartIndex: 0,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        expect(fn () => $guard->check($context, $cycle))->not->toThrow(\Throwable::class);
    });

    it('does not throw when cycle under limit', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 1000,
            maxCycleIterations: 10,
            timeoutSeconds: null,
            enabled: true,
        );

        $cycle = new CycleDetection(
            cycleHash: 'A→B→A',
            cyclePath: ['A', 'B', 'A'],
            completionCount: 9,
            cycleStartIndex: 0,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        expect(fn () => $guard->check($context, $cycle))->not->toThrow(\Throwable::class);
    });

    it('throws when cycle at limit', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 1000,
            maxCycleIterations: 10,
            timeoutSeconds: null,
            enabled: true,
        );

        $cycle = new CycleDetection(
            cycleHash: 'A→B→A',
            cyclePath: ['A', 'B', 'A'],
            completionCount: 10,
            cycleStartIndex: 0,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        expect(fn () => $guard->check($context, $cycle))
            ->toThrow(LoopLimitExceededException::class, "Cycle 'A→B→A' exceeded maximum iterations");
    });

    it('includes cycle hash in exception context', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 1000,
            maxCycleIterations: 5,
            timeoutSeconds: null,
            enabled: true,
        );

        $cycle = new CycleDetection(
            cycleHash: 'A→B→C→A',
            cyclePath: ['A', 'B', 'C', 'A'],
            completionCount: 5,
            cycleStartIndex: 0,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
        );

        try {
            $guard->check($context, $cycle);
        } catch (LoopLimitExceededException $e) {
            expect($e->limitType)->toBe('cycle')
                ->and($e->limitValue)->toBe(5)
                ->and($e->currentValue)->toBe(5)
                ->and($e->cycleHash)->toBe('A→B→C→A')
                ->and($e->pathStack)->toBe(['A', 'B', 'C', 'A']);
        }
    });

    it('does not check cycle when cycle is null', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 100,
            maxCycleIterations: 1,
            timeoutSeconds: null,
            enabled: true,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: $this->startTime,
            globalIterations: 50,
        );

        expect(fn () => $guard->check($context, null))->not->toThrow(\Throwable::class);
    });

});

describe('timeout limit', function () {

    it('does not check when timeoutSeconds is null', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 1000,
            maxCycleIterations: null,
            timeoutSeconds: null,
            enabled: true,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: microtime(true) - 9999, // Way in the past
        );

        expect(fn () => $guard->check($context))->not->toThrow(\Throwable::class);
    });

    it('does not throw when under timeout', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 1000,
            maxCycleIterations: null,
            timeoutSeconds: 30,
            enabled: true,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: microtime(true) - 5, // 5 seconds ago
        );

        expect(fn () => $guard->check($context))->not->toThrow(\Throwable::class);
    });

    it('throws when timeout exceeded', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 1000,
            maxCycleIterations: null,
            timeoutSeconds: 30,
            enabled: true,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: microtime(true) - 31, // 31 seconds ago
            pathStack: ['A', 'B', 'C'],
        );

        expect(fn () => $guard->check($context))
            ->toThrow(LoopLimitExceededException::class, 'exceeded timeout');
    });

    it('includes timeout context in exception', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 1000,
            maxCycleIterations: null,
            timeoutSeconds: 30,
            enabled: true,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: microtime(true) - 35,
            pathStack: ['A', 'B'],
        );

        try {
            $guard->check($context);
        } catch (LoopLimitExceededException $e) {
            expect($e->limitType)->toBe('timeout')
                ->and($e->limitValue)->toBe(30)
                ->and($e->currentValue)->toBe(35)
                ->and($e->pathStack)->toBe(['A', 'B'])
                ->and($e->cycleHash)->toBeNull();
        }
    });

});

describe('combined limits', function () {

    it('checks all enabled limits', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 100,
            maxCycleIterations: 5,
            timeoutSeconds: 60,
            enabled: true,
        );

        // Test global limit triggers first
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: microtime(true),
            globalIterations: 100,
        );

        expect(fn () => $guard->check($context))
            ->toThrow(LoopLimitExceededException::class);

        // Test cycle limit triggers when global is ok
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: microtime(true),
            globalIterations: 50,
        );

        $cycle = new CycleDetection(
            cycleHash: 'A→B→A',
            cyclePath: ['A', 'B', 'A'],
            completionCount: 5,
            cycleStartIndex: 0,
        );

        expect(fn () => $guard->check($context, $cycle))
            ->toThrow(LoopLimitExceededException::class);

        // Test timeout triggers when others are ok
        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: microtime(true) - 61,
            globalIterations: 50,
        );

        expect(fn () => $guard->check($context))
            ->toThrow(LoopLimitExceededException::class);
    });

    it('passes when all limits ok', function () {
        $guard = new LoopGuard(
            maxGlobalIterations: 100,
            maxCycleIterations: 10,
            timeoutSeconds: 60,
            enabled: true,
        );

        $context = new ExecutionContext(
            executionId: $this->executionId,
            startTime: microtime(true) - 30,
            globalIterations: 50,
        );

        $cycle = new CycleDetection(
            cycleHash: 'A→B→A',
            cyclePath: ['A', 'B', 'A'],
            completionCount: 5,
            cycleStartIndex: 0,
        );

        expect(fn () => $guard->check($context, $cycle))->not->toThrow(\Throwable::class);
    });

});
