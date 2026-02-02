<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

/**
 * Exception thrown when loop limits are exceeded.
 * Contains rich context for debugging.
 */
class LoopLimitExceededException extends \RuntimeException
{
    /**
     * @param  string  $message  Exception message
     * @param  string  $limitType  Type of limit exceeded ('global', 'cycle', 'timeout')
     * @param  int  $limitValue  The limit that was set
     * @param  int  $currentValue  The current value that exceeded the limit
     * @param  array<int, string>  $pathStack  Current execution path stack
     * @param  string|null  $cycleHash  The cycle hash if cycle limit exceeded
     */
    public function __construct(
        string $message,
        public readonly string $limitType,
        public readonly int $limitValue,
        public readonly int $currentValue,
        public readonly array $pathStack,
        public readonly ?string $cycleHash = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Get detailed context for logging/debugging.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return [
            'limit_type' => $this->limitType,
            'limit_value' => $this->limitValue,
            'current_value' => $this->currentValue,
            'path_stack' => $this->pathStack,
            'cycle_hash' => $this->cycleHash,
        ];
    }
}
