<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tools;

use JsonSerializable;

/**
 * What one tool call returned. $error is set when the handler failed (it threw, returned a ToolError, or returned an
 * "ERROR:" / "Tool execution error:" string); $result then holds the text the model received.
 */
final class ToolRunResult implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  int|float|string|array<mixed>|null  $result
     */
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $toolName,
        public readonly array $arguments,
        public readonly int|float|string|array|null $result,
        public readonly ?string $error = null,
    ) {}

    /**
     * @return array{tool_call_id: string, tool_name: string, arguments: array<string, mixed>, result: int|float|string|array<mixed>|null, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
            'arguments' => $this->arguments,
            'result' => $this->result,
            'error' => $this->error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
