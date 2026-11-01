<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Exceptions\InvalidExecutionIdException;
use Shahzad\AgenticGraph\Execution\ExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\ExecutionId;
use Shahzad\AgenticGraph\Tracing\TraceContext;

const EXEC_ID_PATTERN = '/^exec_\d+\.\d+_[0-9a-f]{16}$/';

it('generates exec_{microtime}_{16 hex}, unique, at most 200 characters', function () {
    $ids = array_map(fn () => ExecutionId::generate(), range(1, 500));

    expect($ids[0])->toMatch(EXEC_ID_PATTERN)
        ->and(array_unique($ids))->toHaveCount(500)
        ->and(max(array_map('strlen', $ids)))->toBeLessThanOrEqual(200);
});

it('resolve keeps a given id up to 200 characters, generates for null, refuses longer', function () {
    expect(ExecutionId::resolve('my-id'))->toBe('my-id')
        ->and(ExecutionId::resolve(str_repeat('a', 200)))->toHaveLength(200)
        ->and(ExecutionId::resolve(null))->toMatch(EXEC_ID_PATTERN);

    expect(fn () => ExecutionId::resolve(str_repeat('a', 201)))
        ->toThrow(InvalidExecutionIdException::class, '200');
});

it('the TraceContext fallback uses the shared generator, not uniqid', function () {
    expect(TraceContext::fromArray([])->getExecutionId())->toMatch(EXEC_ID_PATTERN);
});

it('execute() rejects a caller-supplied id over 200 characters before running', function () {
    $coordinator = ExecutionCoordinator::fromContainer();

    expect(fn () => $coordinator->execute(createSimpleGraph(), createTestState(), executionId: str_repeat('a', 201)))
        ->toThrow(InvalidExecutionIdException::class);
});
