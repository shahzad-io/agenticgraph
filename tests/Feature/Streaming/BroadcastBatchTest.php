<?php

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;
use Shahzad\AgenticGraph\Streaming\Sinks\BroadcastSink;
use Shahzad\AgenticGraph\Streaming\StreamStatus;
use Shahzad\AgenticGraph\Testing\Llm;

class BatchAnswerNode extends PrismNode
{
    protected ?string $model = 'openai:test-model';

    public function execute(StateContract $state): StateContract
    {
        return $state->set('answer', $this->llm()->prompt('a')->text());
    }
}

/** One long answer: 2500 characters, the fake sends 5 characters per delta, so 500 text_delta events. */
function batchRun(): array
{
    $stream = frameRecorder();
    useStreamBroadcaster(frameRecorder(), $stream);
    Llm::fake([str_repeat('abcd ', 500)]);
    AgenticGraph::register('batch', GraphBuilder::create('batch')
        ->addNode('agent', BatchAnswerNode::class)
        ->setEntryPoint('agent')
        ->addExitPoint('agent')
        ->build());
    $handle = AgenticGraph::broadcast('batch', new State, async: false);
    $stored = iterator_to_array(app(StreamStoreInterface::class)->readFrom((string) $handle->streamId(), 1, 5000), false);

    return [$stream->frames, $stored];
}

/** The Pusher request body size, as BroadcastSink measures it. */
function pusherBytes(array $frame): int
{
    return strlen((string) json_encode(['name' => $frame['event'], 'data' => json_encode($frame['payload']), 'channels' => [$frame['channel']]]));
}

function batchEvent(int $seq, string $type, string $delta = 'x'): StreamEventImpl
{
    return new StreamEventImpl('stream_batch', 'exec_batch', 'agent', null, null, $type, ['delta' => $delta], $seq, $seq, new DateTimeImmutable('2024-01-01T00:00:00Z'));
}

/** A sink on a recorder, fed the given events and closed; returns [event name, inner seqs] per frame. */
function batchSink(array $events, bool $close = true): array
{
    $recorder = frameRecorder();
    $sink = new BroadcastSink($recorder, 'stream_batch');
    foreach ($events as $event) {
        $sink->emit($event);
    }
    if ($close) {
        $sink->close(StreamStatus::SUCCESS);
    }

    return array_map(fn (array $frame) => [$frame['event'], $frame['event'] === 'batch'
        ? array_column($frame['payload']['events'], 'seq')
        : [$frame['payload']['seq'] ?? null]], $recorder->frames);
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
});

// From the scratch reproduction "gap 6".
it('gap 6: one long answer is not sent as one frame per text delta', function () {
    [$frames, $stored] = batchRun();
    $deltas = array_filter($stored, fn ($e) => $e->type() === 'text_delta');

    expect($deltas)->toHaveCount(500) // one store row per delta
        ->and(count($frames))->toBeLessThan(50);
});

it('keeps every event in seq order, the text equal to the store, and no frame over max_bytes', function () {
    config()->set('agenticgraph.streaming.broadcast.max_bytes', 3000);
    [$frames, $stored] = batchRun();
    $events = unpackFrames($frames);
    $sent = array_values(array_filter($events, fn ($e) => $e[0] !== 'stream_close'));

    expect(array_map(fn ($e) => $e[1]['seq'], $sent))->toBe(array_map(fn ($e) => $e->seq(), $stored))
        ->and(implode('', array_map(fn ($e) => $e[1]['payload']['delta'], array_filter($sent, fn ($e) => $e[0] === 'text_delta'))))->toBe(str_repeat('abcd ', 500))
        ->and(max(array_map('pusherBytes', $frames)))->toBeLessThanOrEqual(3000)
        ->and(end($events)[0])->toBe('stream_close');
});

it('sends a batch frame body with stream_id, execution_id and the inner events with their type and nine keys', function () {
    $recorder = frameRecorder();
    $sink = new BroadcastSink($recorder, 'stream_batch');
    $sink->emit(batchEvent(1, 'text_delta', 'a'));
    $sink->emit(batchEvent(2, 'thinking_delta', 'b'));
    $sink->close(StreamStatus::SUCCESS);

    $body = $recorder->frames[0]['payload'];
    expect($recorder->frames[0]['event'])->toBe('batch')
        ->and(array_keys($body))->toBe(['stream_id', 'execution_id', 'events'])
        ->and($body['stream_id'])->toBe('stream_batch')
        ->and($body['execution_id'])->toBe('exec_batch')
        ->and(array_keys($body['events'][0]))->toBe(['type', 'stream_id', 'execution_id', 'node_name', 'span_id', 'parent_span_id', 'seq', 'node_seq', 'occurred_at', 'payload'])
        ->and(array_column($body['events'], 'type'))->toBe(['text_delta', 'thinking_delta']);
});

it('flushes the batch before another event type', function () {
    expect(batchSink([batchEvent(1, 'text_delta'), batchEvent(2, 'text_delta'), batchEvent(3, 'tool_call'), batchEvent(4, 'text_delta')]))
        ->toBe([['batch', [1, 2]], ['tool_call', [3]], ['text_delta', [4]], ['stream_close', [null]]]);
});

it('flushes the batch on close, and sends one buffered delta as a normal frame', function () {
    expect(batchSink([batchEvent(1, 'text_delta'), batchEvent(2, 'thinking_delta')]))->toBe([['batch', [1, 2]], ['stream_close', [null]]])
        ->and(batchSink([batchEvent(1, 'text_delta')]))->toBe([['text_delta', [1]], ['stream_close', [null]]]);
});

it('flushes the batch on interrupt close, with no stream_close', function () {
    $recorder = frameRecorder();
    $sink = new BroadcastSink($recorder, 'stream_batch');
    $sink->emit(batchEvent(1, 'text_delta'));
    $sink->emit(batchEvent(2, 'text_delta'));
    $sink->close(StreamStatus::CANCELLED);

    expect(array_column($recorder->frames, 'event'))->toBe(['batch']);
});

it('flushes when the window has passed at the next delta', function () {
    config()->set('agenticgraph.streaming.broadcast.batch_ms', 5);
    $recorder = frameRecorder();
    $sink = new BroadcastSink($recorder, 'stream_batch');
    $sink->emit(batchEvent(1, 'text_delta'));
    $sink->emit(batchEvent(2, 'text_delta'));
    expect($recorder->frames)->toBe([]);

    usleep(6000);
    $sink->emit(batchEvent(3, 'text_delta'));

    expect(array_column($recorder->frames, 'event'))->toBe(['batch'])
        ->and(array_column($recorder->frames[0]['payload']['events'], 'seq'))->toBe([1, 2, 3]);
});

it('flushes before a delta that would make the request body exceed max_bytes', function () {
    config()->set('agenticgraph.streaming.broadcast.max_bytes', 2500); // a batch keeps 1024 bytes for the request line and headers
    $recorder = frameRecorder();
    $sink = new BroadcastSink($recorder, 'stream_batch');
    foreach (range(1, 6) as $seq) {
        $sink->emit(batchEvent($seq, 'text_delta', str_repeat('y', 200)));
    }
    $sink->close(StreamStatus::SUCCESS);
    $seqs = array_merge(...array_map(fn ($f) => $f['event'] === 'batch' ? array_column($f['payload']['events'], 'seq') : [$f['payload']['seq'] ?? null], $recorder->frames));

    expect(array_column($recorder->frames, 'event'))->toBe(['batch', 'batch', 'batch', 'stream_close'])
        ->and($seqs)->toBe([1, 2, 3, 4, 5, 6, null])
        ->and(max(array_map('pusherBytes', $recorder->frames)))->toBeLessThanOrEqual(2500 - 1024);
});

it('skips a single delta over max_bytes and sends the rest', function () {
    config()->set('agenticgraph.streaming.broadcast.max_bytes', 2500); // a single frame also keeps 1024 bytes for the headers

    expect(batchSink([batchEvent(1, 'text_delta'), batchEvent(2, 'text_delta', str_repeat('z', 2000)), batchEvent(3, 'text_delta')]))
        ->toBe([['text_delta', [1]], ['text_delta', [3]], ['stream_close', [null]]]);
});

it('sends one frame per event with batch_ms=0, as in 0.2.12', function () {
    config()->set('agenticgraph.streaming.broadcast.batch_ms', 0);
    [$frames, $stored] = batchRun();

    expect(array_column($frames, 'event'))->not->toContain('batch')
        ->and(count(array_filter($frames, fn ($f) => $f['event'] === 'text_delta')))->toBe(500)
        ->and(count($frames))->toBe(count($stored) + 1); // every stored event, then stream_close
});

it('leaves room for the request line and headers in a batch: Reverb counts them in max_request_size', function () {
    // Live on Reverb: a 9,9xx byte batch body plus 361 bytes of request line and headers was refused (413).
    [$frames] = batchRun();
    $batches = array_filter($frames, fn ($f) => $f['event'] === 'batch');

    expect($batches)->not->toBeEmpty()
        ->and(max(array_map('pusherBytes', $batches)))->toBeLessThanOrEqual(10000 - 1024);
});
