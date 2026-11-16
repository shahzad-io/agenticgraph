<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Events\StreamEventImpl;

/**
 * Store that records, on each read, whether the file connection holds a PDO. On the second read it runs a query,
 * like a later database step, then returns the terminal event.
 */
function releaseProbeStore(): StreamStoreInterface
{
    return new class implements StreamStoreInterface
    {
        /** @var list<bool> */
        public array $openOnRead = [];

        public ?array $laterQuery = null;

        public function append(StreamEvent $event): void {}

        public function readFrom(string $streamId, int $fromSeq, int $limit = 200): Generator
        {
            $this->openOnRead[] = DB::connection('release_probe')->getRawPdo() !== null;
            if (count($this->openOnRead) < 2) {
                return;
            }
            $this->laterQuery = (array) DB::connection('release_probe')->selectOne('select 1 as one');
            yield new StreamEventImpl($streamId, 'exec-release', null, null, null, 'execution_terminal',
                ['status' => 'completed', 'final_state' => null], $fromSeq, null, new DateTimeImmutable);
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
}

beforeEach(function () {
    $this->probeFile = tempnam(sys_get_temp_dir(), 'ag-release-');
    config()->set('database.connections.release_probe', ['driver' => 'sqlite', 'database' => $this->probeFile, 'prefix' => '']);
    config()->set('agenticgraph.streaming.sse.poll_interval_ms', 1);
    config()->set('agenticgraph.streaming.sse.heartbeat_interval', 0);
});

afterEach(function () {
    DB::purge('release_probe');
    @unlink($this->probeFile);
});

it('releases the database connection before the read loop and reconnects for a later query', function () {
    // The connect-time checks (route middleware, signature, execution lookup) open the connection.
    DB::connection('release_probe')->select('select 1');
    expect(DB::connection('release_probe')->getRawPdo())->not->toBeNull();

    $store = releaseProbeStore();
    $response = (new StreamController)->stream('s-release', Request::create('/streams/s-release'), $store);
    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($store->openOnRead[0])->toBeFalse()
        ->and($store->laterQuery)->toBe(['one' => 1])
        ->and($content)->toContain('execution_terminal');
});

it('keeps an in-memory sqlite connection, which a disconnect would erase', function () {
    DB::connection()->select('select 1');
    $pdo = DB::connection()->getRawPdo();

    $response = (new StreamController)->stream('s-release', Request::create('/streams/s-release'), releaseProbeStore());
    ob_start();
    $response->sendContent();
    ob_end_clean();

    expect(DB::connection()->getRawPdo())->toBe($pdo);
});
