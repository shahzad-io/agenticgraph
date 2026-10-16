<?php

use Illuminate\Http\Request;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;

function sseControlEvent(string $streamId, int $seq, string $type, array $payload = []): StreamEventImpl
{
    return new StreamEventImpl(
        streamId: $streamId,
        executionId: 'exec-control',
        nodeName: null,
        spanId: null,
        parentSpanId: null,
        type: $type,
        payload: $payload,
        seq: $seq,
        nodeSeq: null,
        occurredAt: new DateTimeImmutable('2024-01-01T00:00:00Z')
    );
}

/**
 * @return array{content: string, elapsed: float}
 */
function sseControlRun(string $streamId, ?int $cursor, float $maxDuration, ?StreamStoreInterface $store = null, int $heartbeat = 0): array
{
    config()->set('agenticgraph.streaming.sse.max_duration_seconds', $maxDuration);
    config()->set('agenticgraph.streaming.sse.poll_interval_ms', 20);
    config()->set('agenticgraph.streaming.sse.heartbeat_interval', $heartbeat);

    $query = $cursor === null ? [] : ['cursor' => $cursor];
    $response = (new StreamController)->stream($streamId, Request::create('/streams/'.$streamId, 'GET', $query), $store ?? app(StreamStoreInterface::class));

    $start = microtime(true);
    ob_start();
    $response->sendContent();

    return ['content' => (string) ob_get_clean(), 'elapsed' => microtime(true) - $start];
}

/**
 * Store whose resumed segment (seq 3 and 4) appears on the third read, like a resume
 * landing while a client waits after the interrupted terminal.
 */
function sseResumingStore(string $streamId): StreamStoreInterface
{
    return new class($streamId) implements StreamStoreInterface
    {
        /** @var list<StreamEvent> */
        private array $events;

        private int $reads = 0;

        public function __construct(private readonly string $streamId)
        {
            $this->events = [
                sseControlEvent($streamId, 1, 'node_interrupt'),
                sseControlEvent($streamId, 2, 'execution_terminal', ['status' => 'interrupted', 'final_state' => null]),
            ];
        }

        public function append(StreamEvent $event): void {}

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            if (++$this->reads === 3) {
                $this->events[] = sseControlEvent($this->streamId, 3, 'stream_resume');
                $this->events[] = sseControlEvent($this->streamId, 4, 'execution_terminal', ['status' => 'completed', 'final_state' => null]);
            }
            foreach ($this->events as $event) {
                if ($event->seq() >= $fromSeq) {
                    yield $event;
                }
            }
        }

        public function lastSeq(string $streamId): ?int
        {
            return end($this->events)->seq();
        }

        public function firstSeq(string $streamId): ?int
        {
            return 1;
        }

        public function truncate(string $streamId, int $beforeSeq): void {}
    };
}

function sseSeedStream(string $streamId, int $from, int $to): void
{
    $store = app(StreamStoreInterface::class);
    for ($seq = $from; $seq <= $to; $seq++) {
        $store->append(sseControlEvent($streamId, $seq, 'text_delta', ['delta' => (string) $seq]));
    }
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.streaming.sse.retry_ms', 1500);
});

it('starts every response with the retry field', function () {
    $result = sseControlRun('stream-retry', null, 0.1);

    expect($result['content'])->toStartWith("retry: 1500\n\n");
});

it('sends heartbeats as control events without id or seq', function () {
    $result = sseControlRun('stream-heartbeat', null, 1.2, heartbeat: 1);

    expect($result['content'])->toMatch('/\n\nevent: stream_heartbeat\ndata: \{"control":"heartbeat","timestamp":"[^"]+"\}\n\n/')
        ->and($result['content'])->not->toContain('id:')
        ->and($result['content'])->not->toContain('"seq"');
});

it('closes at max_duration with a planned reconnect control event', function () {
    $result = sseControlRun('stream-planned-close', null, 0.3);

    expect($result['content'])->toBe("retry: 1500\n\nevent: stream_reconnect\ndata: {\"control\":\"reconnect\",\"after_ms\":1500}\n\n");
});

it('keeps an interrupted stream open so the resumed segment arrives on the same connection', function () {
    $result = sseControlRun('stream-resume', null, 5, sseResumingStore('stream-resume'));

    expect($result['content'])->toContain("id: 2\nevent: execution_terminal")
        ->and($result['content'])->toContain("id: 3\nevent: stream_resume")
        ->and($result['content'])->toContain("id: 4\nevent: execution_terminal")
        ->and($result['content'])->not->toContain('stream_reconnect')
        ->and($result['elapsed'])->toBeLessThan(2.0);
});

it('never sends replay_unavailable while a stream waits at the cursor', function () {
    $streamId = 'stream-waiting';
    $store = app(StreamStoreInterface::class);
    $store->append(sseControlEvent($streamId, 1, 'node_interrupt'));
    $store->append(sseControlEvent($streamId, 2, 'execution_terminal', ['status' => 'interrupted', 'final_state' => null]));

    $result = sseControlRun($streamId, 2, 1.3);

    expect($result['content'])->not->toContain('replay_unavailable')
        ->and($result['content'])->toEndWith("event: stream_reconnect\ndata: {\"control\":\"reconnect\",\"after_ms\":1500}\n\n");
});

it('never sends replay_unavailable for cursor 0 on an empty stream', function () {
    $result = sseControlRun('stream-not-started', 0, 1.3);

    expect($result['content'])->not->toContain('replay_unavailable')
        ->and($result['content'])->toContain('stream_reconnect');
});

it('sends replay_unavailable once and closes when the cursor is below the first stored seq', function () {
    $streamId = 'stream-truncated';
    sseSeedStream($streamId, 1, 7);
    app(StreamStoreInterface::class)->truncate($streamId, 5);

    $result = sseControlRun($streamId, 2, 5);

    expect($result['content'])->toBe("retry: 1500\n\nevent: stream_replay_unavailable\ndata: {\"control\":\"replay_unavailable\",\"cursor\":2}\n\n")
        ->and($result['elapsed'])->toBeLessThan(1.0);
});

it('sends replay_unavailable when the stream is empty and the cursor is past 0', function () {
    $result = sseControlRun('stream-expired', 10, 5);

    expect($result['content'])->toBe("retry: 1500\n\nevent: stream_replay_unavailable\ndata: {\"control\":\"replay_unavailable\",\"cursor\":10}\n\n")
        ->and($result['elapsed'])->toBeLessThan(1.0);
});

it('replays from the first stored seq when the cursor is just below it', function () {
    $streamId = 'stream-edge';
    sseSeedStream($streamId, 1, 7);
    app(StreamStoreInterface::class)->truncate($streamId, 5);

    $result = sseControlRun($streamId, 4, 2);

    expect($result['content'])->toContain("id: 5\nevent: text_delta")
        ->and($result['content'])->toContain("id: 7\nevent: text_delta")
        ->and($result['content'])->not->toContain('replay_unavailable');
});

it('reports firstSeq on the database store', function () {
    $store = app(StreamStoreInterface::class);

    expect($store->firstSeq('stream-first-db'))->toBeNull();

    sseSeedStream('stream-first-db', 1, 4);
    expect($store->firstSeq('stream-first-db'))->toBe(1);

    $store->truncate('stream-first-db', 3);
    expect($store->firstSeq('stream-first-db'))->toBe(3);
});
