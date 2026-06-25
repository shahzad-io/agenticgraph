<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Mcp;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Tracing\TraceContext;

final class McpRequest
{
    public function __construct(
        private PendingTextRequest $request,
        private string $provider,
        private string $model,
        private string $nodeName,
        private bool $trackTokens,
        private Dispatcher $events,
        private bool $captureToolCalls = true
    ) {}

    /**
     * @param  array<int, mixed>  $arguments
     */
    /**
     * @param  array<int, mixed>  $arguments
     */
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

        if ($this->captureToolCalls) {
            $this->dispatchToolCalls($response);
        }

        if ($this->trackTokens) {
            $promptTokens = $response->usage->promptTokens;
            $completionTokens = $response->usage->completionTokens;
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->events->dispatch(TokensUsed::fromLlmCall(
                node: $this->nodeName,
                callStartMicrotime: $startTime,
                callDurationMs: $durationMs,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                callType: 'mcp',
                model: "{$this->provider}:{$this->model}"
            ));
        }

        return $response;
    }

    public function asMcp(): McpResponse
    {
        return new McpResponse($this->asText());
    }

    private function dispatchToolCalls(mixed $response): void
    {
        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (BindingResolutionException $e) {
            // TraceContext not bound, tracing not active
            return;
        }

        $executionId = $traceContext->getExecutionId();
        $spanId = $traceContext->getSpanId();
        $mcpResponse = new McpResponse($response);
        $toolResults = $mcpResponse->toolResults();

        if ($toolResults !== []) {
            foreach ($toolResults as $toolResult) {
                $toolName = $toolResult['toolName'] ?? null;
                if (! is_string($toolName) || $toolName === '') {
                    continue;
                }

                $this->events->dispatch(new ToolCallCaptured(
                    executionId: $executionId,
                    spanId: $spanId,
                    toolName: $toolName,
                    toolCallId: is_string($toolResult['toolCallId'] ?? null) ? $toolResult['toolCallId'] : null,
                    toolType: 'mcp',
                    toolProvider: $this->toolProviderFromName($toolName),
                    toolRequestId: is_string($toolResult['toolCallResultId'] ?? null) ? $toolResult['toolCallResultId'] : null,
                    inputSize: $this->payloadSize($toolResult['args'] ?? null),
                    outputSize: $this->payloadSize($toolResult['result'] ?? null),
                    durationMs: null,
                    startedAt: new \DateTimeImmutable,
                    status: 'success',
                    errorType: null,
                    errorMessage: null
                ));
            }

            return;
        }

        $toolCalls = $mcpResponse->toolCalls();
        foreach ($toolCalls as $toolCall) {
            $toolName = $toolCall['name'] ?? null;
            if (! is_string($toolName) || $toolName === '') {
                continue;
            }

            $this->events->dispatch(new ToolCallCaptured(
                executionId: $executionId,
                spanId: $spanId,
                toolName: $toolName,
                toolCallId: is_string($toolCall['id'] ?? null) ? $toolCall['id'] : null,
                toolType: 'mcp',
                toolProvider: $this->toolProviderFromName($toolName),
                inputSize: $this->payloadSize($toolCall['arguments'] ?? null),
                outputSize: null,
                durationMs: null,
                startedAt: new \DateTimeImmutable,
                toolRequestId: is_string($toolCall['id'] ?? null) ? $toolCall['id'] : null,
                status: 'success',
                errorType: null,
                errorMessage: null
            ));
        }
    }

    private function toolProviderFromName(string $toolName): ?string
    {
        if (! str_contains($toolName, '__')) {
            return null;
        }

        $parts = explode('__', $toolName);
        $provider = $parts[1] ?? null;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    private function payloadSize(mixed $payload): ?int
    {
        if ($payload === null) {
            return null;
        }

        if (is_string($payload)) {
            return strlen($payload);
        }

        $encoded = json_encode($payload);
        if (! is_string($encoded)) {
            return null;
        }

        return strlen($encoded);
    }
}
