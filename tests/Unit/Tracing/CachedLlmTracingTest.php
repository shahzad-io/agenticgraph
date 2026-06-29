<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Tests\Unit\Tracing\MockPulseExporter;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;

it('maps cached TokensUsed events to llm spans and llm_calls with cached marker', function (): void {
    $exporter = new MockPulseExporter;
    $listener = new TracingListener($exporter);

    $startedAt = new DateTimeImmutable('2024-01-01 10:00:00');
    $interval = new DateInterval('PT0S');
    $interval->f = 0.002;
    $endedAt = $startedAt->add($interval);

    $event = new TokensUsed(
        node: 'analyze',
        promptTokens: 0,
        completionTokens: 0,
        totalTokens: 0,
        executionId: 'exec_cached',
        parentSpanId: 'span_parent',
        spanId: 'span_llm_cached',
        callType: 'cached',
        model: 'openai:gpt-4o',
        durationMs: 2.0,
        startedAt: $startedAt,
        endedAt: $endedAt,
        prompt: 'Analyze this topic',
        response: 'cached analysis',
        cached: true,
        cacheKey: 'agenticgraph:prism:v1:hash',
    );

    $listener->onTokensUsed($event);

    expect($exporter->spans)->toHaveCount(1)
        ->and($exporter->spans[0]['span_type'])->toBe('llm')
        ->and($exporter->spans[0]['cached'])->toBeTrue()
        ->and($exporter->spans[0]['cost_usd'])->toBe(0.0)
        ->and($exporter->llmCalls)->toHaveCount(1)
        ->and($exporter->llmCalls[0]['call_type'])->toBe('cached')
        ->and($exporter->llmCalls[0]['cached'])->toBeTrue()
        ->and($exporter->llmCalls[0]['cache_key'])->toBe('agenticgraph:prism:v1:hash');
});
