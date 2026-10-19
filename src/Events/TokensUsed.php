<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Shahzad\AgenticGraph\Tracing\CaptureDepth;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * Event dispatched when tokens are used during an API call.
 */
class TokensUsed implements ShouldQueue
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
        public readonly bool $cached = false,
        public readonly ?string $cacheKey = null,
        public readonly bool $streamed = false,
        /** Reasoning tokens. Null: the provider does not report them (unknown, not zero). */
        public readonly ?int $thoughtTokens = null,
        /** Input tokens served from the provider's cache, not part of promptTokens. Null: not reported. */
        public readonly ?int $cacheReadTokens = null,
        /** Input tokens written to the provider's cache, not part of promptTokens. Null: not reported. */
        public readonly ?int $cacheWriteTokens = null,
        /** Items context() put in the prompt. Null: context() was not used. */
        public readonly ?int $contextCount = null,
        /** @var list<string>|null The first 20 of those items' ids. */
        public readonly ?array $contextIds = null,
    ) {}

    /**
     * @param  list<string>|null  $contextIds
     */
    public static function fromLlmCall(
        string $node,
        float $callStartMicrotime,
        float $callDurationMs,
        int $promptTokens,
        int $completionTokens,
        string $callType,
        ?string $model = null,
        ?string $prompt = null,
        ?string $response = null,
        bool $streamed = false,
        ?int $thoughtTokens = null,
        ?int $cacheReadTokens = null,
        ?int $cacheWriteTokens = null,
        ?int $contextCount = null,
        ?array $contextIds = null,
    ): self {
        $clock = ExecutionClock::tryGet();
        $traceContext = TraceContext::tryGet();

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
            prompt: self::promptForEvent($prompt),
            response: self::responseForEvent($response),
            cached: false,
            cacheKey: null,
            streamed: $streamed,
            thoughtTokens: $thoughtTokens,
            cacheReadTokens: $cacheReadTokens,
            cacheWriteTokens: $cacheWriteTokens,
            contextCount: $contextCount,
            contextIds: $contextIds,
        );
    }

    /**
     * @param  list<string>|null  $contextIds
     */
    public static function fromCacheHit(
        string $node,
        float $callStartMicrotime,
        float $callDurationMs,
        ?string $model = null,
        ?string $prompt = null,
        ?string $response = null,
        ?string $cacheKey = null,
        ?int $contextCount = null,
        ?array $contextIds = null,
    ): self {
        $clock = ExecutionClock::tryGet();
        $traceContext = TraceContext::tryGet();

        $executionId = $traceContext?->getExecutionId() ?? $clock?->getExecutionId();
        $parentSpanId = $traceContext?->getSpanId();
        $spanId = uniqid('span_', true);
        $startedAt = $clock
            ? $clock->spanStartedAt($callStartMicrotime)
            : (\DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $callStartMicrotime)) ?: new \DateTimeImmutable);

        if ($clock) {
            $endedAt = $clock->spanEndedAt($callStartMicrotime, $callDurationMs);
        } else {
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
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
            executionId: $executionId,
            parentSpanId: $parentSpanId,
            spanId: $spanId,
            callType: 'cached',
            model: $model,
            durationMs: $callDurationMs,
            startedAt: $startedAt,
            endedAt: $endedAt,
            prompt: self::promptForEvent($prompt),
            response: self::responseForEvent($response),
            cached: true,
            cacheKey: $cacheKey,
            contextCount: $contextCount,
            contextIds: $contextIds,
        );
    }

    public static function promptForEvent(?string $prompt): ?string
    {
        if (! CaptureDepth::shouldCarryPromptInEvent() || $prompt === null) {
            return null;
        }

        return self::truncateText($prompt, self::MAX_TEXT_LENGTH);
    }

    public static function responseForEvent(?string $response): ?string
    {
        if (! CaptureDepth::shouldCarryResponseInEvent() || $response === null) {
            return null;
        }

        return self::truncateText($response, self::MAX_TEXT_LENGTH);
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
}
