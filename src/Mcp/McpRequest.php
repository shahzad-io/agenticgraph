<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Mcp;

use Illuminate\Contracts\Events\Dispatcher;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Shahzad\AgenticGraph\Events\TokensUsed;

final class McpRequest
{
    public function __construct(
        private PendingTextRequest $request,
        private string $provider,
        private string $model,
        private string $nodeName,
        private bool $trackTokens,
        private Dispatcher $events
    ) {}

    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->request->{$name}(...$arguments);

        if ($result instanceof PendingTextRequest) {
            return $this;
        }

        return $result;
    }

    public function asText(): mixed
    {
        $startTime = microtime(true);
        $response = $this->request->asText();

        if ($this->trackTokens && isset($response->usage)) {
            $promptTokens = $response->usage->promptTokens ?? 0;
            $completionTokens = $response->usage->completionTokens ?? 0;
            $this->events->dispatch(new TokensUsed(
                node: $this->nodeName,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                totalTokens: $promptTokens + $completionTokens,
                callType: 'mcp',
                model: "{$this->provider}:{$this->model}",
                durationMs: (microtime(true) - $startTime) * 1000
            ));
        }

        return $response;
    }

    public function asMcp(): McpResponse
    {
        return new McpResponse($this->asText());
    }
}
