<?php

use Illuminate\Http\Request;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;

/**
 * Octane with Swoole writes the response through its own output handler. The controller ended every buffer, that
 * one too, so each event went to the worker's stdout and Octane's ob_end_clean() threw (500 on every request).
 */
it('keeps a user output handler and writes the events through it', function () {
    $store = new class implements StreamStoreInterface
    {
        public function append(StreamEvent $event): void {}

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            yield new StreamEventImpl($streamId, 'exec-ob', null, null, null, 'execution_terminal',
                ['status' => 'completed', 'final_state' => null], 1, null, new DateTimeImmutable);
        }

        public function lastSeq(string $streamId): ?int
        {
            return null;
        }

        public function firstSeq(string $streamId): ?int
        {
            return null;
        }

        public function truncate(string $streamId, int $beforeSeq): void {}
    };
    config()->set('agenticgraph.streaming.sse.heartbeat_interval', 0);
    $response = (new StreamController)->stream('stream_ob', Request::create(streamUrlFor('stream_ob')), $store);

    $base = ob_get_level();
    $written = '';
    ob_start(function (string $buffer) use (&$written): string {
        $written .= $buffer;

        return '';
    });
    $env = app()['env'];
    app()['env'] = 'production'; // the buffer handling is skipped under unit tests
    try {
        $response->sendContent();
    } finally {
        app()['env'] = $env;
        $kept = ob_get_level() === $base + 1;
        while (ob_get_level() > $base) {
            ob_end_clean();
        }
        while (ob_get_level() < $base) {
            ob_start();
        }
    }

    expect($kept)->toBeTrue()
        ->and($written)->toStartWith("retry: 2000\n\n")
        ->and($written)->toContain('event: execution_terminal');
});
