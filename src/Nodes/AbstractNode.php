<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Nodes;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\StreamContext;
use Shahzad\AgenticGraph\Tracing\TraceContext;

abstract class AbstractNode implements NodeInterface
{
    /** @var string|null Override property for node name */
    protected ?string $name = null;

    public function getName(): string
    {
        // Allow nodes to override with a property
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        // Otherwise, generate readable name from class name
        $className = class_basename(static::class);

        // Remove "Node" suffix if present
        $className = preg_replace('/Node$/', '', $className);

        // Convert PascalCase to Title Case
        return preg_replace('/(?<!^)[A-Z]/', ' $0', $className);
    }

    public function getDescription(): ?string
    {
        return null;
    }

    public function getRetryAttempts(): int
    {
        return 3;
    }

    public function getRetryDelay(): int
    {
        return 1000;
    }

    public function shouldRetry(\Throwable $exception): bool
    {
        return true;
    }

    public function getTags(): array
    {
        return [];
    }

    public function onBefore(StateContract $state): void {}

    public function onAfter(StateContract $state, StateContract $result): void {}

    public function onError(\Throwable $exception, StateContract $state): void {}

    protected function isStreaming(): bool
    {
        if (! app()->bound(StreamContext::class)) {
            return false;
        }

        return app(StreamContext::class)->sink() !== null;
    }

    /**
     * @param  \Closure(): void  $callback
     */
    protected function whenStreaming(\Closure $callback): void
    {
        if (! $this->isStreaming()) {
            return;
        }

        try {
            $callback();
        } catch (\Throwable $e) {
            if ((bool) config('agenticgraph.logging.enabled', true)) {
                Log::warning('Stream callback failed', [
                    'node' => $this->getName(),
                    'error_type' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function streamEvent(string $type, array $payload): void
    {
        if (! $this->isStreaming()) {
            return;
        }

        $context = app(StreamContext::class);
        $sink = $context->sink();
        if ($sink === null) {
            return;
        }

        $traceContext = null;
        try {
            $traceContext = app(TraceContext::class);
        } catch (BindingResolutionException $e) {
        }

        $session = $context->session();
        $seq = ++$session->seq;
        $nodeSeq = $context->nextNodeSeq($this->getName());

        $sink->emit(new StreamEventImpl(
            $session->streamId,
            $session->executionId,
            $this->getName(),
            $traceContext?->getSpanId(),
            $traceContext?->getParentSpanId(),
            $type,
            $payload,
            $seq,
            $nodeSeq,
            new \DateTimeImmutable
        ));
    }
}
