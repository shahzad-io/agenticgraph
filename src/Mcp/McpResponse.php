<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Mcp;

final class McpResponse
{
    public function __construct(private mixed $response) {}

    public function raw(): mixed
    {
        return $this->response;
    }

    public function cleanText(): string
    {
        $text = $this->getText();
        if ($text !== '') {
            return $text;
        }

        $toolResults = $this->toolResults();
        if ($toolResults === []) {
            return '';
        }

        $parts = [];
        foreach ($toolResults as $toolResult) {
            $result = $toolResult['result'] ?? null;
            if (is_string($result) && trim($result) !== '') {
                $parts[] = trim($result);
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toolResults(): array
    {
        $toolResults = $this->getToolResultsRaw();

        return array_map(fn ($item) => $this->normalizeToolResult($item), $toolResults);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toolCalls(): array
    {
        $toolCalls = $this->getToolCallsRaw();

        return array_map(fn ($item) => $this->normalizeToolCall($item), $toolCalls);
    }

    private function getText(): string
    {
        $text = $this->getValue($this->response, 'text');

        return is_string($text) ? trim($text) : '';
    }

    /**
     * @return array<int, mixed>
     */
    private function getToolResultsRaw(): array
    {
        $toolResults = $this->getValue($this->response, 'toolResults');

        if ($toolResults instanceof \Illuminate\Support\Collection) {
            $toolResults = $toolResults->all();
        }

        if (is_array($toolResults) && $toolResults !== []) {
            return $toolResults;
        }

        return $this->extractFromSteps('toolResults');
    }

    /**
     * @return array<int, mixed>
     */
    private function getToolCallsRaw(): array
    {
        $toolCalls = $this->getValue($this->response, 'toolCalls');

        if ($toolCalls instanceof \Illuminate\Support\Collection) {
            $toolCalls = $toolCalls->all();
        }

        if (is_array($toolCalls) && $toolCalls !== []) {
            return $toolCalls;
        }

        return $this->extractFromSteps('toolCalls');
    }

    /**
     * @return array<int, mixed>
     */
    private function extractFromSteps(string $key): array
    {
        $steps = $this->getValue($this->response, 'steps');

        if ($steps instanceof \Illuminate\Support\Collection) {
            $steps = $steps->all();
        }

        if (! is_array($steps)) {
            return [];
        }

        $items = [];
        foreach ($steps as $step) {
            $value = $this->getValue($step, $key);
            if ($value instanceof \Illuminate\Support\Collection) {
                $value = $value->all();
            }
            if (is_array($value) && $value !== []) {
                $items = array_merge($items, $value);
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeToolResult(mixed $item): array
    {
        return [
            'toolCallId' => $this->getValue($item, 'toolCallId') ?? $this->getValue($item, 'tool_call_id'),
            'toolName' => $this->getValue($item, 'toolName') ?? $this->getValue($item, 'name'),
            'args' => $this->getValue($item, 'args') ?? $this->getValue($item, 'arguments'),
            'result' => $this->getValue($item, 'result'),
            'toolCallResultId' => $this->getValue($item, 'toolCallResultId') ?? $this->getValue($item, 'tool_call_result_id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeToolCall(mixed $item): array
    {
        if (is_object($item) && method_exists($item, 'arguments')) {
            $arguments = $item->arguments();
        } else {
            $arguments = $this->getValue($item, 'arguments');
        }

        return [
            'id' => $this->getValue($item, 'id'),
            'name' => $this->getValue($item, 'name'),
            'arguments' => $arguments,
        ];
    }

    private function getValue(mixed $item, string $key): mixed
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }

        if (is_object($item) && property_exists($item, $key)) {
            $property = new \ReflectionProperty($item, $key);
            if ($property->isPublic()) {
                return $item->{$key};
            }
        }

        return null;
    }
}
