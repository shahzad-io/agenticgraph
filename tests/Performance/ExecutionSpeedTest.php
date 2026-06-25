<?php

use Illuminate\Support\Facades\Redis;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Streaming\StreamOptions;

it('executes 100-node graph under 5 seconds', function () {
    $graph = createLargeGraph(100);
    $start = microtime(true);
    (new GraphExecutor)->execute($graph, createTestState());
    $duration = microtime(true) - $start;
    expect($duration)->toBeLessThan(5.0);
});

class PerfStreamNode extends AbstractNode
{
    protected ?string $name = 'perf';

    public function execute(StateContract $state): StateContract
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->streamEvent('text_delta', ['delta' => (string) $i]);
        }

        return $state->set('count', 1000);
    }
}

class LoadStreamNode extends AbstractNode
{
    protected ?string $name = 'load';

    public function execute(StateContract $state): StateContract
    {
        for ($i = 0; $i < 10000; $i++) {
            $this->streamEvent('text_delta', ['delta' => (string) $i]);
        }

        return $state->set('count', 10000);
    }
}

it('keeps streaming overhead within 2x baseline', function () {
    $graph = createLargeGraph(50);
    AgenticGraph::register('perf-overhead', $graph);

    $executor = new GraphExecutor;
    $baselineSamples = [];
    for ($i = 0; $i < 5; $i++) {
        $start = microtime(true);
        $executor->execute($graph, createTestState());
        $baselineSamples[] = microtime(true) - $start;
    }
    $baseline = array_sum($baselineSamples) / count($baselineSamples);

    $options = new StreamOptions(delivery: 'iterator', storeEnabled: false);
    $streamSamples = [];
    for ($i = 0; $i < 5; $i++) {
        $start = microtime(true);
        $handle = $executor->executeWithStreaming('perf-overhead', createTestState(), $options);
        iterator_to_array($handle->getIterator());
        $streamSamples[] = microtime(true) - $start;
    }
    $stream = array_sum($streamSamples) / count($streamSamples);

    expect($stream)->toBeLessThan($baseline * 2.0);
});

it('streams with sse sink within 2x memory sink time', function () {
    $graph = createLargeGraph(25);
    AgenticGraph::register('perf-sse', $graph);

    $executor = new GraphExecutor;
    $memoryOptions = new StreamOptions(delivery: 'iterator', storeEnabled: false);
    $sseOptions = new StreamOptions(delivery: 'sse', storeEnabled: false);

    $start = microtime(true);
    $memoryHandle = $executor->executeWithStreaming('perf-sse', createTestState(), $memoryOptions);
    iterator_to_array($memoryHandle->getIterator());
    $memoryDuration = microtime(true) - $start;

    $start = microtime(true);
    $sseHandle = $executor->executeWithStreaming('perf-sse', createTestState(), $sseOptions);
    iterator_to_array($sseHandle->getIterator());
    $sseDuration = microtime(true) - $start;

    expect($sseDuration)->toBeLessThan($memoryDuration * 2.0);
});

it('streams 1000 events with database store under 5 seconds', function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');

    $graph = GraphBuilder::create('perf-db-store')
        ->addNode('perf', PerfStreamNode::class)
        ->setEntryPoint('perf')
        ->addExitPoint('perf')
        ->build();
    AgenticGraph::register('perf-db-store', $graph);

    $executor = new GraphExecutor;
    $options = new StreamOptions(delivery: 'iterator', storeEnabled: true);

    $start = microtime(true);
    $handle = $executor->executeWithStreaming('perf-db-store', createTestState(), $options);
    iterator_to_array($handle->getIterator());
    $duration = microtime(true) - $start;

    expect($duration)->toBeLessThan(5.0);
});

it('streams 1000 events with redis store under 5 seconds', function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis is not available');
    }

    config()->set('agenticgraph.streaming.store.driver', 'redis');

    $graph = GraphBuilder::create('perf-redis-store')
        ->addNode('perf', PerfStreamNode::class)
        ->setEntryPoint('perf')
        ->addExitPoint('perf')
        ->build();
    AgenticGraph::register('perf-redis-store', $graph);

    $executor = new GraphExecutor;
    $options = new StreamOptions(delivery: 'iterator', storeEnabled: true);

    $start = microtime(true);
    $handle = $executor->executeWithStreaming('perf-redis-store', createTestState(), $options);
    iterator_to_array($handle->getIterator());
    $duration = microtime(true) - $start;

    expect($duration)->toBeLessThan(5.0);
});

it('handles 100 stream sessions with 10k events each', function () {
    if (! (bool) env('AGENTICGRAPH_LOAD_TEST', false)) {
        $this->markTestSkipped('Load test disabled');
    }

    $graph = GraphBuilder::create('load-stream')
        ->addNode('load', LoadStreamNode::class)
        ->setEntryPoint('load')
        ->addExitPoint('load')
        ->build();
    AgenticGraph::register('load-stream', $graph);

    $executor = new GraphExecutor;
    $options = new StreamOptions(delivery: 'iterator', storeEnabled: false);

    $handles = [];
    for ($i = 0; $i < 100; $i++) {
        $handles[] = $executor->executeWithStreaming('load-stream', createTestState(['run' => $i]), $options);
    }

    $counts = [];
    foreach ($handles as $handle) {
        $events = iterator_to_array($handle->getIterator());
        $counts[] = count(array_filter($events, fn ($event) => $event->type() === 'text_delta'));
    }

    expect(min($counts))->toBe(10000)
        ->and(max($counts))->toBe(10000);
});
