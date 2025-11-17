<?php

namespace Shahzad\AgenticGraph\Events;

class TokensUsed
{
    public function __construct(
        public readonly string $node,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens
    ) {
    }
}