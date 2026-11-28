<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

/**
 * How a failed resume, queued or sync, resolves its interrupt.
 *
 * @internal
 */
trait ResolvesFailedInterrupt
{
    private function resolveAsFailed(InterruptRecord $interrupt, \Throwable $e): void
    {
        if ($interrupt->status() === 'resolved') {
            return;
        }
        $metadata = $interrupt->metadata();
        $details = $this->errorDetails($e);
        $interrupt->update(['metadata' => array_merge(is_array($metadata) ? $metadata : [], ['resume_error' => $details, 'last_error' => $details])]);
        $interrupt->resolve(['outcome' => 'failed']);
    }

    /**
     * @return array{type: string, message: string, occurred_at: string}
     */
    private function errorDetails(\Throwable $e): array
    {
        return ['type' => $e::class, 'message' => $e->getMessage(), 'occurred_at' => now()->toIsoString()];
    }
}
