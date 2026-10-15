<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Exceptions;

use RuntimeException;
use Shahzad\AgenticGraph\Execution\InterruptRecord;

/**
 * The interrupt passed its expires_at and agenticgraph:interrupt:expire ended its execution. It cannot be resumed.
 */
final class InterruptExpiredException extends RuntimeException
{
    public const REASON = 'interrupt_expired';

    public function __construct(public readonly InterruptRecord $interrupt)
    {
        $expiresAt = $interrupt->getAttribute('expires_at');

        parent::__construct(sprintf(
            'Interrupt %s for execution %s expired%s and cannot be resumed.',
            (string) $interrupt->interruptId(),
            (string) $interrupt->executionId(),
            $expiresAt instanceof \DateTimeInterface ? ' at '.$expiresAt->format(\DateTimeInterface::ATOM) : '',
        ));
    }

    /**
     * The one resume guard: every resume path calls this with the interrupt checkpoint it is about to resume. Without
     * a checkpoint id (an ended execution's state no longer names one), the execution's latest interrupt decides.
     */
    public static function throwIfExpired(?string $checkpointId, ?string $executionId = null): void
    {
        if (! (bool) config('agenticgraph.interrupts.persistence_enabled', true)) {
            return;
        }

        $interrupt = match (true) {
            $checkpointId !== null => InterruptRecord::query()->where('checkpoint_id', $checkpointId)->latest('id')->first(),
            $executionId !== null => InterruptRecord::query()->forExecution($executionId)->latest('id')->first(),
            default => null,
        };

        if ($interrupt?->status() === 'expired') {
            throw new self($interrupt);
        }
    }
}
