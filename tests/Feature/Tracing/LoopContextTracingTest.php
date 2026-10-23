<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Routers\AbstractRouter;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tests\Unit\Tracing\MockPulseExporter;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;

class LoopTraceLinearNodeA extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('a', true);
    }
}

class LoopTraceLinearNodeB extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('b', true);
    }
}

class LoopTraceLinearNodeC extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('c', true);
    }
}

class LoopTraceWriterNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('writer_visits', ((int) $state->get('writer_visits', 0)) + 1);
    }
}

class LoopTraceCriticNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('critic_visits', ((int) $state->get('critic_visits', 0)) + 1);
    }
}

class LoopTraceExitNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }
}

class LoopTraceCriticRouter extends AbstractRouter
{
    public function route(StateContract $state): string
    {
        return ((int) $state->get('writer_visits', 0)) >= 2 ? 'end' : 'writer';
    }

    public function getAvailableRoutes(): array
    {
        return ['writer', 'end'];
    }
}

beforeEach(function (): void {
    config()->set('agenticgraph.tracing.enabled', true);
    config()->set('agenticgraph.tracing.api_key', 'ap_test');
    config()->set('agenticgraph.execution.loop.enabled', false);

    $exporter = new MockPulseExporter;
    app()->instance(TracerExporter::class, $exporter);

    $this->exporter = $exporter;
});

it('exports no false loop_context cycles on a linear traced graph', function (): void {
    $graph = GraphBuilder::create('loop-trace-linear')
        ->addNode('analyze', LoopTraceLinearNodeA::class)
        ->addNode('generate_meta', LoopTraceLinearNodeB::class)
        ->addNode('keywords', LoopTraceLinearNodeC::class)
        ->addEdge('analyze', 'generate_meta')
        ->addEdge('generate_meta', 'keywords')
        ->setEntryPoint('analyze')
        ->addExitPoint('keywords')
        ->build();

    app(GraphRegistry::class)->register('loop-trace-linear', $graph);

    app(GraphExecutor::class)->executeWithOptions(
        $graph,
        new State,
        new ExecutionOptions(ExecutionMode::SYNC, StreamingMode::NONE),
    );

    $nodeSpans = array_values(array_filter(
        $this->exporter->spans,
        fn (array $span): bool => ($span['span_type'] ?? '') === 'node',
    ));

    expect($nodeSpans)->toHaveCount(3);

    foreach ($nodeSpans as $span) {
        expect($span['loop_context']['in_cycle'] ?? null)->toBeFalse()
            ->and($span['loop_context']['active_cycles'] ?? null)->toBe([]);
    }
});

it('exports the correct multi-hop cycle on a writer critic loop', function (): void {
    $graph = GraphBuilder::create('loop-trace-writer-critic')
        ->addNode('writer', LoopTraceWriterNode::class)
        ->addNode('critic', LoopTraceCriticNode::class)
        ->addNode('end', LoopTraceExitNode::class)
        ->addEdge('writer', 'critic')
        ->addConditionalEdge('critic', LoopTraceCriticRouter::class)
        ->setEntryPoint('writer')
        ->addExitPoint('end')
        ->build();

    app(GraphRegistry::class)->register('loop-trace-writer-critic', $graph);

    app(GraphExecutor::class)->executeWithOptions(
        $graph,
        new State,
        new ExecutionOptions(ExecutionMode::SYNC, StreamingMode::NONE),
    );

    $writerSpans = array_values(array_filter(
        $this->exporter->spans,
        fn (array $span): bool => ($span['node_name'] ?? '') === 'writer' && ($span['span_type'] ?? '') === 'node',
    ));

    expect($writerSpans)->toHaveCount(2)
        ->and($writerSpans[0]['loop_context']['in_cycle'] ?? null)->toBeFalse()
        ->and($writerSpans[1]['loop_context']['in_cycle'] ?? null)->toBeTrue();

    $activeCycles = $writerSpans[1]['loop_context']['active_cycles'] ?? [];
    $cycleHashes = array_column($activeCycles, 'cycle');

    expect($cycleHashes)->toContain('writer→critic→writer');
});
