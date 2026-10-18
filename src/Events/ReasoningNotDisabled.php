<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

/**
 * Dispatched when a call asked for reasoning 'off' and the reply still reports thought tokens. Not an error: some
 * models always reason a little.
 */
final class ReasoningNotDisabled
{
    public function __construct(
        public readonly string $node,
        public readonly string $model,
        public readonly int $thoughtTokens,
    ) {}
}
