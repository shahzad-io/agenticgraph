<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tools;

use Prism\Prism\ValueObjects\ToolOutput;

/**
 * What a terminal tool returns when it has data for the app next to the reply text:
 *
 *     ->using(fn (string $id): TerminalResult => TerminalResult::reply("Order {$id} ships today.", ['order_id' => $id]))
 *
 * The run ends only when the tool's name is in terminalTools(). The text is the reply (and the tool result in the
 * steps); the data is LlmResponse::$terminalData. A terminal tool can also return a plain string.
 */
final readonly class TerminalResult extends ToolOutput
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(string $text, public array $data)
    {
        parent::__construct($text);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function reply(string $text, array $data = []): self
    {
        return new self($text, $data);
    }
}
