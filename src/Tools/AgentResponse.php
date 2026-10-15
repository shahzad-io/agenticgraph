<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Tools;

use Prism\Prism\Text\Response as TextResponse;

/**
 * PrismNode::agent()'s result: Prism's text response plus $results, the tool results of every step. Prism's own
 * $toolResults holds only the final step's.
 */
final readonly class AgentResponse extends TextResponse
{
    /**
     * @param  list<ToolRunResult>  $results
     */
    public function __construct(TextResponse $response, public array $results)
    {
        parent::__construct(
            $response->steps,
            $response->text,
            $response->finishReason,
            $response->toolCalls,
            $response->toolResults,
            $response->usage,
            $response->meta,
            $response->messages,
            $response->additionalContent,
            $response->raw,
        );
    }
}
