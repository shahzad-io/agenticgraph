<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

/**
 * Interface for events that can be batched together.
 *
 * Events implementing this interface can be accumulated and dispatched
 * as a single batch, reducing overhead in high-volume scenarios.
 *
 * @example
 * ```php
 * class TokensUsed implements BatchableEventInterface
 * {
 *     public function getBatchKey(): string
 *     {
 *         return "tokens:{$this->node}";
 *     }
 *
 *     public function merge(self $other): self
 *     {
 *         return new self(
 *             node: $this->node,
 *             promptTokens: $this->promptTokens + $other->promptTokens,
 *             completionTokens: $this->completionTokens + $other->completionTokens,
 *             totalTokens: $this->totalTokens + $other->totalTokens,
 *         );
 *     }
 * }
 * ```
 */
interface BatchableEventInterface
{
    /**
     * Get the batch key for grouping events.
     *
     * Events with the same batch key will be merged together.
     */
    public function getBatchKey(): string;

    /**
     * Merge another event of the same type into this one.
     *
     * @param  BatchableEventInterface  $other  The event to merge into this one
     * @return BatchableEventInterface A new event representing the merged data
     */
    public function merge(BatchableEventInterface $other): BatchableEventInterface;
}
