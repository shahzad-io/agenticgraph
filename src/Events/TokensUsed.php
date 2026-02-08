<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * Event dispatched when tokens are used during an API call.
 *
 * This event implements BatchableEventInterface to allow efficient
 * batching of high-frequency token usage events.
 */
class TokensUsed implements BatchableEventInterface, ShouldQueue
{
    use SerializesModels;

    /**
     * Maximum allowed length for prompt/response text before hard truncation.
     * Prevents memory issues and keeps events reasonably sized.
     */
    private const MAX_TEXT_LENGTH = 100000; // 100KB hard ceiling

    public function __construct(
        public readonly string $node,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens,
        public readonly ?string $executionId = null,
        public readonly ?string $parentSpanId = null,
        public readonly ?string $spanId = null,
        public readonly string $callType = 'text',
        public readonly ?string $model = null,
        public readonly ?float $durationMs = null,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $endedAt = null,
        public readonly ?string $prompt = null,
        public readonly ?string $response = null,
    ) {}

    public static function fromLlmCall(
        string $node,
        float $callStartMicrotime,
        float $callDurationMs,
        int $promptTokens,
        int $completionTokens,
        string $callType,
        ?string $model = null,
        ?string $prompt = null,
        ?string $response = null
    ): self {
        $clock = ExecutionClock::tryGet();
        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
        }

        $executionId = $traceContext?->getExecutionId() ?? $clock?->getExecutionId();
        $parentSpanId = $traceContext?->getSpanId();
        $spanId = uniqid('span_', true);
        $startedAt = $clock
            ? $clock->spanStartedAt($callStartMicrotime)
            : (\DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $callStartMicrotime)) ?: new \DateTimeImmutable);

        if ($clock) {
            $endedAt = $clock->spanEndedAt($callStartMicrotime, $callDurationMs);
        } else {
            // Use DateInterval to properly handle fractional seconds
            // PHP's modify() ignores decimal points (e.g., "0.082563" becomes "82563" seconds)
            $durationSeconds = $callDurationMs / 1000;
            $wholeSeconds = intval($durationSeconds);
            $fractionalSeconds = $durationSeconds - $wholeSeconds;

            $interval = new \DateInterval("PT{$wholeSeconds}S");
            if ($fractionalSeconds > 0) {
                $interval->f = $fractionalSeconds;
            }

            $endedAt = $startedAt->add($interval);
        }

        return new self(
            node: $node,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $promptTokens + $completionTokens,
            executionId: $executionId,
            parentSpanId: $parentSpanId,
            spanId: $spanId,
            callType: $callType,
            model: $model,
            durationMs: $callDurationMs,
            startedAt: $startedAt,
            endedAt: $endedAt,
            prompt: $prompt !== null ? self::truncateText($prompt, self::MAX_TEXT_LENGTH) : null,
            response: $response !== null ? self::truncateText($response, self::MAX_TEXT_LENGTH) : null,
        );
    }

    /**
     * Truncate text to maximum allowed length with ellipsis indicator.
     */
    private static function truncateText(?string $text, int $maxLength): ?string
    {
        if ($text === null || strlen($text) <= $maxLength) {
            return $text;
        }

        // Try to truncate at sentence boundary first
        $truncated = substr($text, 0, $maxLength - 20);
        $lastSentence = strrpos($truncated, '. ');

        if ($lastSentence !== false && $lastSentence > $maxLength * 0.7) {
            return substr($truncated, 0, $lastSentence + 1).' [...truncated]';
        }

        // Fallback: truncate at word boundary
        $lastSpace = strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            return substr($truncated, 0, $lastSpace).' [...truncated]';
        }

        // Final fallback: hard truncate
        return substr($text, 0, $maxLength - 15).' [...truncated]';
    }

    /**
     * Get the batch key for grouping token usage events.
     *
     * Groups events by node and call type for efficient aggregation.
     */
    public function getBatchKey(): string
    {
        $executionId = $this->executionId ?? 'unknown';

        return "tokens:{$executionId}:{$this->node}:{$this->callType}";
    }

    /**
     * Merge another TokensUsed event into this one.
     *
     * Aggregates token counts and uses the latest timestamp.
     * Note: Prompt and response text are NOT merged (keeps first event's text only).
     */
    public function merge(BatchableEventInterface $other): BatchableEventInterface
    {
        if (! $other instanceof self) {
            throw new \InvalidArgumentException('Cannot merge with different event type');
        }

        if ($this->executionId !== $other->executionId) {
            throw new \InvalidArgumentException('Cannot merge events from different executions');
        }

        $latestEndedAt = $this->endedAt;
        if ($other->endedAt !== null && ($this->endedAt === null || $other->endedAt > $this->endedAt)) {
            $latestEndedAt = $other->endedAt;
        }

        $earliestStartedAt = $this->startedAt;
        if ($other->startedAt !== null && ($this->startedAt === null || $other->startedAt < $this->startedAt)) {
            $earliestStartedAt = $other->startedAt;
        }

        // When merging, keep the first event's prompt/response text
        // (merging text content doesn't make sense)
        return new self(
            node: $this->node,
            promptTokens: $this->promptTokens + $other->promptTokens,
            completionTokens: $this->completionTokens + $other->completionTokens,
            totalTokens: $this->totalTokens + $other->totalTokens,
            executionId: $this->executionId,
            parentSpanId: $this->parentSpanId ?? $other->parentSpanId,
            spanId: $this->spanId ?? $other->spanId,
            callType: $this->callType,
            model: $this->model ?? $other->model,
            durationMs: ($this->durationMs ?? 0) + ($other->durationMs ?? 0),
            startedAt: $earliestStartedAt,
            endedAt: $latestEndedAt,
            prompt: $this->prompt,
            response: $this->response,
        );
    }
}
