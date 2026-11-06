<?php

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamEvent;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;

const ERROR_DETAILS_SECRET = 'SECRET-DSN postgres://user:pw@10.0.0.5/db?sslmode=require&application_name=agenticgraph';

class ErrorDetailsThrowingNode extends AbstractNode
{
    public function getRetryAttempts(): int
    {
        return 1;
    }

    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException(ERROR_DETAILS_SECRET);
    }
}

class ErrorDetailsProviderNode extends AbstractNode
{
    public static ?Throwable $throw = null;

    public function getRetryAttempts(): int
    {
        return 0;
    }

    public function execute(StateContract $state): StateContract
    {
        throw self::$throw;
    }
}

class ErrorDetailsBroadcaster implements Broadcaster
{
    /** @var list<array{event: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function auth($request)
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return null;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $this->sent[] = ['event' => $event, 'payload' => $payload];
    }
}

/**
 * @return list<StreamEvent>
 */
function errorDetailsEvents(string $streamId): array
{
    return array_values(iterator_to_array(app(StreamStoreInterface::class)->readFrom($streamId, 1, 100), false));
}

function errorDetailsFailingRun(string $node = ErrorDetailsThrowingNode::class): string
{
    if (! app()->bound(Broadcaster::class) || ! (app(Broadcaster::class) instanceof ErrorDetailsBroadcaster)) {
        app()->instance(Broadcaster::class, new ErrorDetailsBroadcaster);
    }
    AgenticGraph::register('error-details', GraphBuilder::create('error-details')
        ->addNode('boom', $node)
        ->setEntryPoint('boom')
        ->addExitPoint('boom')
        ->build());
    try {
        AgenticGraph::execute('error-details', new State, new ExecutionOptions(mode: ExecutionMode::SYNC, streaming: StreamingMode::BROADCAST));
    } catch (Throwable) {
    }

    return (string) DB::table('agenticgraph_executions')->where('graph_name', 'error-details')->value('stream_id');
}

beforeEach(function () {
    config()->set('agenticgraph.streaming.store.driver', 'database');
    config()->set('agenticgraph.execution.queue_connection', 'sync');
    app()->forgetInstance(StreamStoreInterface::class);
});

function errorDetailsFailedJobStream(): string
{
    $executionId = 'exec_'.uniqid();
    $streamId = 'stream_'.uniqid();
    $options = new ExecutionOptions(mode: ExecutionMode::ASYNC, streaming: StreamingMode::SSE);
    app(ExecutionRepository::class)->queued($executionId, 'not-on-worker', [], $streamId, $options);
    $job = new ExecuteGraphJob('not-on-worker', $executionId, [], null, 'span_root', $options, $streamId, 0);
    try {
        dispatch($job);
    } catch (Throwable) {
    }

    return $streamId;
}

it('sends generic error values by default on graph_failed and the failed terminal', function () {
    $streamId = errorDetailsFailedJobStream();
    $events = errorDetailsEvents($streamId);
    $failed = collect($events)->first(fn (StreamEvent $e) => $e->type() === 'graph_failed');
    $terminal = collect($events)->first(fn (StreamEvent $e) => $e->type() === 'execution_terminal');

    expect($failed->payload())->toMatchArray(['error_type' => 'ExecutionFailed', 'message' => 'Execution failed'])
        ->and($terminal->payload()['status'])->toBe('failed')
        ->and($terminal->payload()['meta'])->toMatchArray(['error_type' => 'ExecutionFailed', 'message' => 'Execution failed'])
        ->and(json_encode($events === [] ? [] : array_map(fn (StreamEvent $e) => $e->payload(), $events)))->not->toContain('not-on-worker');
});

it('sends generic values for node_error, graph_failed and the terminal of a node that throws', function () {
    $events = errorDetailsEvents(errorDetailsFailingRun());
    $json = json_encode(array_map(fn (StreamEvent $e) => $e->payload(), $events));

    expect(array_map(fn (StreamEvent $e) => $e->type(), $events))->toContain('node_error', 'graph_failed', 'execution_terminal')
        ->and($json)->not->toContain('SECRET-DSN')
        ->and($json)->not->toContain('RuntimeException');
});

it('keeps the real message in the stored execution error', function () {
    errorDetailsFailingRun();

    expect(DB::table('agenticgraph_executions')->where('graph_name', 'error-details')->value('error'))->toContain('SECRET-DSN');
});

it('sends the exception class and message when AGENTICGRAPH_STREAM_EXPOSE_ERROR_DETAILS is on', function () {
    config()->set('agenticgraph.streaming.expose_error_details', true);

    $events = errorDetailsEvents(errorDetailsFailingRun());
    $byType = fn (string $type) => collect($events)->first(fn (StreamEvent $e) => $e->type() === $type)->payload();

    expect($byType('node_error')['error_type'])->toBe(RuntimeException::class)
        ->and($byType('node_error')['message'])->toBe(ERROR_DETAILS_SECRET)
        ->and($byType('graph_failed')['error_type'])->toBe(RuntimeException::class)
        ->and($byType('graph_failed')['message'])->toBe(ERROR_DETAILS_SECRET)
        ->and($byType('execution_terminal')['meta']['error_type'])->toBe(RuntimeException::class)
        ->and($byType('execution_terminal')['meta']['message'])->toBe(ERROR_DETAILS_SECRET);
});

it('cuts the message to capture.max_length with the truncation marker', function () {
    config()->set('agenticgraph.streaming.expose_error_details', true);
    config()->set('agenticgraph.tracing.capture.max_length', 50);

    $events = errorDetailsEvents(errorDetailsFailingRun());
    $message = collect($events)->first(fn (StreamEvent $e) => $e->type() === 'graph_failed')->payload()['message'];

    expect(strlen($message))->toBeLessThanOrEqual(50)
        ->and($message)->toContain('[...truncated '.strlen(ERROR_DETAILS_SECRET).']');
});

it('keeps the message out with capture.errors=class even when details are on', function () {
    config()->set('agenticgraph.streaming.expose_error_details', true);
    config()->set('agenticgraph.tracing.capture.errors', 'class');

    $events = errorDetailsEvents(errorDetailsFailingRun());
    $failed = collect($events)->first(fn (StreamEvent $e) => $e->type() === 'graph_failed')->payload();

    expect($failed['error_type'])->toBe(RuntimeException::class)
        ->and($failed['message'])->toBe('Execution failed')
        ->and(json_encode($failed))->not->toContain('SECRET-DSN');
});

it('sends generic values in the broadcast stream_close of a failed run', function () {
    $broadcaster = new ErrorDetailsBroadcaster;
    app()->instance(Broadcaster::class, $broadcaster);
    errorDetailsFailingRun();

    $close = collect($broadcaster->sent)->first(fn (array $m) => $m['event'] === 'stream_close');

    expect($close)->not->toBeNull()
        ->and($close['payload']['status'])->toBe('error')
        ->and(json_encode($close['payload']))->not->toContain('SECRET-DSN');
});

function errorDetailsHttpError(int $status): RequestException
{
    return new RequestException(new Response(new GuzzleHttp\Psr7\Response($status, [], 'raw provider body sk-live-123')));
}

it('uses a fixed provider category for node_error and graph_failed when a provider exception fails the node', function (Closure $make, string $category) {
    ErrorDetailsProviderNode::$throw = $make();

    $events = errorDetailsEvents(errorDetailsFailingRun(ErrorDetailsProviderNode::class));
    $byType = fn (string $type) => collect($events)->first(fn (StreamEvent $e) => $e->type() === $type)->payload();
    $terminal = $byType('execution_terminal');
    $json = json_encode(array_map(fn (StreamEvent $e) => $e->payload(), $events));

    expect($byType('node_error')['error_type'])->toBe($category)
        ->and($byType('graph_failed')['error_type'])->toBe($category)
        ->and($terminal['meta']['error_type'])->toBe($category)
        ->and($byType('graph_failed')['message'])->toBe(match ($category) {
            'rate_limited' => 'The model provider is rate limiting requests',
            'provider_unavailable' => 'The model provider is unavailable',
            default => 'The model provider returned an error',
        })
        ->and($json)->not->toContain('sk-live')
        ->and($json)->not->toContain('raw provider body')
        ->and($json)->not->toContain('You hit a provider rate limit')
        ->and($json)->not->toContain('ExecutionFailed');
})->with([
    'prism rate limited' => [fn () => PrismRateLimitedException::make([], 30), 'rate_limited'],
    'http 429' => [fn () => errorDetailsHttpError(429), 'rate_limited'],
    'prism overloaded' => [fn () => PrismProviderOverloadedException::make('openai'), 'provider_unavailable'],
    'http 503' => [fn () => errorDetailsHttpError(503), 'provider_unavailable'],
    'connection failed' => [fn () => new ConnectionException('cURL error 28: timeout sk-live-123'), 'provider_unavailable'],
    'http 400' => [fn () => errorDetailsHttpError(400), 'provider_error'],
    'prism exception' => [fn () => new PrismException('provider said sk-live-123'), 'provider_error'],
    'wrapped provider exception' => [fn () => new RuntimeException('node failed', 0, PrismRateLimitedException::make()), 'rate_limited'],
]);

it('keeps ExecutionFailed for an exception that is not a provider error', function () {
    ErrorDetailsProviderNode::$throw = new InvalidArgumentException('bad input');

    $events = errorDetailsEvents(errorDetailsFailingRun(ErrorDetailsProviderNode::class));

    expect(collect($events)->first(fn (StreamEvent $e) => $e->type() === 'graph_failed')->payload()['error_type'])->toBe('ExecutionFailed');
});

it('sends the real provider exception class and message when details are on', function () {
    config()->set('agenticgraph.streaming.expose_error_details', true);
    ErrorDetailsProviderNode::$throw = new PrismException('provider said sk-live-123');

    $events = errorDetailsEvents(errorDetailsFailingRun(ErrorDetailsProviderNode::class));
    $failed = collect($events)->first(fn (StreamEvent $e) => $e->type() === 'graph_failed')->payload();

    expect($failed['error_type'])->toBe(PrismException::class)
        ->and($failed['message'])->toBe('provider said sk-live-123');
});
