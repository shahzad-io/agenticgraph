<?php

use Illuminate\Support\Facades\Bus;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamSession;

class StreamIdFormatNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('done', true);
    }
}

// uniqid('stream_', true) was time-based (guessable) and held a dot, which a Laravel channel parameter never matches.
it('mints stream ids from random bytes, without a dot', function (ExecutionMode $mode, StreamingMode $streaming) {
    Bus::fake();
    AgenticGraph::register('stream-id-format', GraphBuilder::create('stream-id-format')
        ->addNode('a', StreamIdFormatNode::class)->setEntryPoint('a')->addExitPoint('a')->build());

    $ids = [];
    foreach (range(1, 3) as $i) {
        $ids[] = (string) AgenticGraph::execute('stream-id-format', new State, new ExecutionOptions(mode: $mode, streaming: $streaming))->streamId();
    }

    expect($ids)->each->toMatch('/^stream_[0-9a-f]{32}$/')
        ->and(array_unique($ids))->toHaveCount(3);
})->with([
    'sync iterator' => [ExecutionMode::SYNC, StreamingMode::ITERATOR],
    'async sse' => [ExecutionMode::ASYNC, StreamingMode::SSE],
    'async broadcast' => [ExecutionMode::ASYNC, StreamingMode::BROADCAST],
]);

it('has one random id source for every place that mints a stream id', function () {
    expect(StreamSession::newId())->toMatch('/^stream_[0-9a-f]{32}$/');
    $minted = shell_exec('grep -rn "uniqid(.stream_" '.escapeshellarg(dirname(__DIR__, 3).'/src'));
    expect(trim((string) $minted))->toBe('');
});
