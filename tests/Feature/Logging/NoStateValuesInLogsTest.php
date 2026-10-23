<?php

use Illuminate\Bus\Batch;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\RoutingEngine;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\TemplateInterpolator;
use Shahzad\AgenticGraph\Routers\PrismRouter;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\State\StateManager;

const LOG_SENTINEL = 'log-sentinel-91c2-secret';

class ProviderDownPrismRouter extends PrismRouter
{
    protected function prismClient(): PrismClient
    {
        throw new RuntimeException('provider unavailable');
    }
}

/**
 * Routes every log call (Log facade and injected LoggerInterface) into one Monolog TestHandler.
 */
function logSentinelChannel(): TestHandler
{
    config([
        'agenticgraph.logging.enabled' => true,
        'agenticgraph.router.max_retries' => 1,
        'agenticgraph.router.retry_delay_ms' => 0,
        'logging.channels.sentinel' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug'],
        'logging.default' => 'sentinel',
    ]);
    Log::forgetChannel('sentinel');

    return Log::channel('sentinel')->getLogger()->getHandlers()[0];
}

function logSentinelDump(TestHandler $handler): string
{
    return json_encode(array_map(
        fn ($record) => ['message' => $record->message, 'context' => $record->context],
        $handler->getRecords()
    ), JSON_THROW_ON_ERROR);
}

function logSentinelState(): State
{
    return new State(['secret_system_prompt' => LOG_SENTINEL, 'internal_customer_email' => 'x@secret.example']);
}

it('logs keys, router and exception but no state values when PrismRouter fails after retries', function () {
    $handler = logSentinelChannel();
    $router = new ProviderDownPrismRouter(['a' => 'route a', 'b' => 'route b'], defaultRoute: 'b');

    expect($router->route(logSentinelState()))->toBe('b');

    $record = collect($handler->getRecords())->firstWhere('message', 'PrismRouter failed after retries');

    expect($record)->not->toBeNull()
        ->and($record->context['state_keys'])->toBe(['secret_system_prompt', 'internal_customer_email'])
        ->and($record->context['router'])->toBe(ProviderDownPrismRouter::class)
        ->and($record->context['exception_class'])->toBe(RuntimeException::class)
        ->and($record->context['exception'])->toBe('provider unavailable')
        ->and($record->context)->toHaveKeys(['file', 'line', 'available_routes'])
        ->and($record->context)->not->toHaveKeys(['state', 'trace'])
        ->and(logSentinelDump($handler))->not->toContain(LOG_SENTINEL);
});

it('logs response shape but not model output when the routing response has no route key', function () {
    $handler = logSentinelChannel();
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['reasoning' => LOG_SENTINEL])
            ->withUsage(new Usage(5, 5))
            ->withMeta(new Meta('fake-sentinel', 'fake-model')),
    ]);

    $router = new PrismRouter(['a' => 'route a', 'b' => 'route b'], defaultRoute: 'b');

    expect($router->route(new State(['message' => 'hello'])))->toBe('b');

    $record = collect($handler->getRecords())->firstWhere('message', 'PrismRouter structured response missing route key');

    expect($record)->not->toBeNull()
        ->and($record->context)->toMatchArray([
            'router' => PrismRouter::class,
            'response_type' => 'array',
            'response_keys' => ['reasoning'],
            'route_valid' => false,
            'expected_routes' => ['a', 'b'],
        ])
        ->and($record->context)->not->toHaveKey('response')
        ->and(logSentinelDump($handler))->not->toContain(LOG_SENTINEL);
});

it('logs colliding key and branch names but no branch values on parallel merge collisions', function () {
    $handler = logSentinelChannel();

    app(StateManager::class)->warnParallelMergeCollisions(
        app(LoggerInterface::class),
        new State(['topic' => 'demo']),
        ['branch_a', 'branch_b'],
        [new State(['secret' => LOG_SENTINEL.'-a']), new State(['secret' => LOG_SENTINEL.'-b'])],
    );

    $record = $handler->getRecords()[0] ?? null;

    expect($record)->not->toBeNull()
        ->and($record->context)->toMatchArray([
            'key' => 'secret',
            'kept_branch' => 'branch_b',
            'colliding_branches' => ['branch_a', 'branch_b'],
        ])
        ->and($record->context)->not->toHaveKey('branch_values')
        ->and(logSentinelDump($handler))->not->toContain(LOG_SENTINEL);
});

it('logs exception class, message, file and line but no stack trace arguments on batch failure', function () {
    $handler = logSentinelChannel();
    // php.ini-production sets ignore_args On and string_param_max_len 0 (args print as '...').
    // The length applies when the trace is formatted, so it stays at 15 until the end of the test.
    $previous = ini_set('zend.exception_ignore_args', '0');
    $previousMaxLen = ini_set('zend.exception_string_param_max_len', '15');
    $makeError = fn (string $prompt) => new RuntimeException('batch boom');
    $error = $makeError(LOG_SENTINEL);
    ini_set('zend.exception_ignore_args', (string) $previous);

    expect($error->getTraceAsString())->toContain(substr(LOG_SENTINEL, 0, 10));

    $repo = Mockery::mock(ExecutionRepository::class);
    $repo->shouldReceive('failed')->once()->andReturn(true);
    $batch = Mockery::mock(Batch::class);
    $batch->id = 'batch-sentinel';

    $coordinator = new ParallelExecutionCoordinator(
        new RoutingEngine(app()),
        Mockery::mock(StateManager::class),
        Mockery::mock(CacheManager::class),
        Mockery::mock(BusDispatcher::class),
        app(LoggerInterface::class),
        $repo,
        Mockery::mock(Dispatcher::class),
        Mockery::mock(Container::class),
        Mockery::mock(CheckpointManager::class),
        [],
        static fn () => null,
        static fn () => null,
        static fn ($job) => $job
    );
    (new ReflectionMethod($coordinator, 'handleBatchFailure'))->invoke($coordinator, $batch, 'exec-sentinel', $error);

    $record = collect($handler->getRecords())->firstWhere('message', 'Batch execution failed');

    expect($record)->not->toBeNull()
        ->and($record->context)->toMatchArray([
            'execution_id' => 'exec-sentinel',
            'batch_id' => 'batch-sentinel',
            'exception_class' => RuntimeException::class,
            'error' => 'batch boom',
        ])
        ->and($record->context)->toHaveKeys(['file', 'line'])
        ->and($record->context)->not->toHaveKey('trace')
        ->and(logSentinelDump($handler))->not->toContain(substr(LOG_SENTINEL, 0, 10));

    ini_set('zend.exception_string_param_max_len', (string) $previousMaxLen);
});

it('cuts the missing variable name in template exceptions to 64 characters', function () {
    $longKey = str_repeat('k', 200);

    try {
        TemplateInterpolator::interpolate('{'.$longKey.'}', []);
        expect(false)->toBeTrue('expected TemplateException');
    } catch (TemplateException $e) {
        expect($e->getMessage())->toBe('Missing template variable: '.str_repeat('k', 64));
    }
});
