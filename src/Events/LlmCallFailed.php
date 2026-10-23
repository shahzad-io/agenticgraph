<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Events;

use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceTime;

/**
 * Dispatched when a model call fails with a provider exception, before it is rethrown. The failed counterpart of
 * TokensUsed: usage and cost are unknown (the provider may still bill the call), so it carries none.
 */
final class LlmCallFailed
{
    /**
     * @param  list<string>|null  $contextIds
     * @param  array{name: string, version: string, hash: string}|null  $promptFile
     */
    public function __construct(
        public readonly string $node,
        public readonly string $callType,
        public readonly ?string $model,
        public readonly \Throwable $exception,
        public readonly float $durationMs,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?string $executionId = null,
        public readonly ?string $parentSpanId = null,
        public readonly bool $streamed = false,
        public readonly ?int $contextCount = null,
        public readonly ?array $contextIds = null,
        public readonly ?array $promptFile = null,
    ) {}

    /**
     * @param  list<string>|null  $contextIds
     * @param  array{name: string, version: string, hash: string}|null  $promptFile
     */
    public static function fromException(
        string $node,
        float $callStartMicrotime,
        string $callType,
        ?string $model,
        \Throwable $exception,
        bool $streamed = false,
        ?int $contextCount = null,
        ?array $contextIds = null,
        ?array $promptFile = null,
    ): self {
        $clock = ExecutionClock::tryGet();
        $traceContext = TraceContext::tryGet();

        return new self(
            node: $node,
            callType: $callType,
            model: $model,
            exception: $exception,
            durationMs: (microtime(true) - $callStartMicrotime) * 1000,
            startedAt: $clock?->spanStartedAt($callStartMicrotime) ?? TraceTime::fromMicrotime($callStartMicrotime),
            executionId: $traceContext?->getExecutionId() ?? $clock?->getExecutionId(),
            parentSpanId: $traceContext?->getSpanId(),
            streamed: $streamed,
            contextCount: $contextCount,
            contextIds: $contextIds,
            promptFile: $promptFile,
        );
    }
}
