<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tools;

use Countable;
use JsonSerializable;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * What one PrismNode::tools() call did: the calls the model requested, what each handler returned, and any text the
 * model wrote. tools() is single-step, so the results are not sent back to the model; use agent() for that.
 * count() is the number of calls. Stored in state it serializes through toArray().
 */
final class ToolRun implements Countable, JsonSerializable
{
    /**
     * @param  list<ToolCall>  $calls
     * @param  list<ToolRunResult>  $results
     */
    public function __construct(
        public readonly array $calls,
        public readonly array $results,
        public readonly string $text = '',
    ) {}

    public function count(): int
    {
        return count($this->calls);
    }

    /**
     * @return array{calls: list<array<string, mixed>>, results: list<array<string, mixed>>, text: string}
     */
    public function toArray(): array
    {
        return [
            'calls' => array_map(fn (ToolCall $call): array => $call->toArray(), $this->calls),
            'results' => array_map(fn (ToolRunResult $result): array => $result->toArray(), $this->results),
            'text' => $this->text,
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
