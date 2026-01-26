<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

class TokensUsed
{
    public function __construct(
        public readonly string $node,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens,
        // New fields for AgenticPulse observability (backward compatible with defaults)
        public readonly string $callType = 'text',     // 'text', 'structured', 'tools', 'router'
        public readonly ?string $model = null,
        public readonly ?float $durationMs = null,
    ) {}
}
