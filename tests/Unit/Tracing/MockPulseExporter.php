<?php

namespace Shahzad\AgenticGraph\Tests\Unit\Tracing;

use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceTime;

class MockPulseExporter extends PulseExporter
{
    public array $spans = [];

    public array $llmCalls = [];

    public array $traces = [];

    public bool $enabled = true;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function addSpan(array $span): void
    {
        $this->spans[] = $span;
    }

    public function addLlmCall(array $call): void
    {
        $this->llmCalls[] = $call;
    }

    public function startTrace(
        string $traceId,
        string $graphName,
        array $initialStateKeys = [],
        ?\DateTimeImmutable $startedAt = null,
        ?array $initialStateData = null
    ): void {
        $this->traces[$traceId] = [
            'graph_name' => $graphName,
            'initial_state_keys' => $initialStateKeys,
            'started_at' => $startedAt ? TraceTime::format($startedAt) : null,
            'initial_state' => $initialStateData,
        ];
    }
}
