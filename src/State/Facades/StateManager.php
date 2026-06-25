<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\State\Facades;

use Illuminate\Support\Facades\Facade;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\State\StateManager as StateManagerService;

/**
 * Facade for the StateManager service.
 *
 * This facade provides a convenient static interface to the StateManager service,
 * while maintaining backward compatibility with the previous static implementation.
 *
 * @method static array{added: array<string, mixed>, modified: array<string, array{from: mixed, to: mixed}>, removed: array<string, mixed>} diff(StateContract $old, StateContract $new)
 * @method static array<int, array{id: string, node: string, created_at: string}> getVersions(string $executionId)
 * @method static StateContract rollback(string $executionId, string $checkpointId)
 * @method static string encrypt(StateContract $state)
 * @method static StateContract decrypt(string $payload)
 * @method static string compress(StateContract $state)
 * @method static StateContract decompress(string $payload)
 * @method static StateContract mergeAll(array<int, StateContract> $states)
 *
 * @see StateManagerService
 */
class StateManager extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return StateManagerService::class;
    }
}
