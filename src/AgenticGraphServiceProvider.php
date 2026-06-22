<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Shahzad\AgenticGraph\Adapters\PrismFacadeClient;
use Shahzad\AgenticGraph\Commands\AgenticGraphCommand;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\ParallelExecutorInterface;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Contracts\RouterResolverInterface;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Contracts\StreamingExecutorInterface;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionResumer;
use Shahzad\AgenticGraph\Execution\ExecutionStatusResolver;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\InterruptHandlerResolver;
use Shahzad\AgenticGraph\Execution\InterruptManager;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\RoutingEngine;
use Shahzad\AgenticGraph\Execution\TraceContextProvider;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Storage\RedisStorage;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\RetentionPolicyFactory;
use Shahzad\AgenticGraph\Streaming\Store\StreamStoreManager;
use Shahzad\AgenticGraph\Streaming\StreamErrorHandler;
use Shahzad\AgenticGraph\Streaming\StreamingExecutionCoordinator;
use Shahzad\AgenticGraph\Streaming\StreamObserverFactory;
use Shahzad\AgenticGraph\Streaming\StreamSinkFactory;
use Shahzad\AgenticGraph\Tracing\ExecutionClockFactory;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceContextFactory;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AgenticGraphServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('agenticgraph')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_agenticgraph_executions_table')
            ->hasMigration('create_agenticgraph_checkpoints_table')
            ->hasMigration('create_agenticgraph_stream_events_table')
            ->hasMigration('create_agenticgraph_interrupts_table')
            ->hasCommand(AgenticGraphCommand::class)
            ->hasCommand(\Shahzad\AgenticGraph\Console\ListGraphsCommand::class)
            ->hasCommand(\Shahzad\AgenticGraph\Console\VisualizeGraphCommand::class)
            ->hasCommand(\Shahzad\AgenticGraph\Console\ExecuteGraphCommand::class)
            ->hasCommand(\Shahzad\AgenticGraph\Console\CheckpointListCommand::class)
            ->hasCommand(\Shahzad\AgenticGraph\Console\CheckpointResumeCommand::class)
            ->hasCommand(\Shahzad\AgenticGraph\Console\CheckpointDeleteCommand::class)
            ->hasCommand(\Shahzad\AgenticGraph\Console\CheckpointCleanupCommand::class)
            ->hasCommand(\Shahzad\AgenticGraph\Console\ExecutionsListCommand::class);
    }

    public function packageBooted(): void
    {
        $this->app->bind(PrismClient::class, PrismFacadeClient::class);

        // Bind StorageInterface to the configured storage driver
        $this->app->bind(\Shahzad\AgenticGraph\Contracts\StorageInterface::class, function ($app) {
            return match (config('agenticgraph.storage')) {
                'redis' => $app->make(RedisStorage::class),
                'file' => $app->make(FileStorage::class),
                default => $app->make(DatabaseStorage::class),
            };
        });

        // Register GraphRegistry as singleton with cache backend
        $this->app->singleton(\Shahzad\AgenticGraph\Graph\GraphRegistry::class, function ($app) {
            return new \Shahzad\AgenticGraph\Graph\GraphRegistry($app['cache.store']);
        });

        // Register PulseExporter as singleton
        $this->app->singleton(PulseExporter::class, function ($app) {
            return new PulseExporter;
        });

        // Register StateManager as singleton with dependencies
        $this->app->singleton(StateManager::class, function ($app) {
            $storage = match (config('agenticgraph.storage')) {
                'redis' => $app->make(RedisStorage::class),
                'file' => $app->make(FileStorage::class),
                default => $app->make(DatabaseStorage::class),
            };

            return new StateManager(
                $app->make(DatabaseManager::class),
                $storage,
                $app->make(Dispatcher::class)
            );
        });

        // Register CheckpointManager as singleton with dependencies
        $this->app->singleton(CheckpointManager::class, function ($app) {
            $storage = match (config('agenticgraph.storage')) {
                'redis' => $app->make(RedisStorage::class),
                'file' => $app->make(FileStorage::class),
                default => $app->make(DatabaseStorage::class),
            };

            return new CheckpointManager(
                $storage,
                $app->make(DatabaseManager::class),
                $app->make(Dispatcher::class),
                (int) config('agenticgraph.checkpoint.frequency', 1),
                (int) config('agenticgraph.checkpoint.max_state_size', 10485760),
                $app->make(ExecutionRepository::class),
            );
        });

        $this->app->singleton(ExecutionContextManager::class, function ($app) {
            return new ExecutionContextManager(
                $app->bound(PulseExporter::class) ? $app->make(PulseExporter::class) : null,
                $app->bound(\Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation::class)
                    ? $app->make(\Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation::class)
                    : null,
                $app->make(TraceContextFactory::class),
                $app->make(ExecutionClockFactory::class),
                $app,
                $app->make(\Psr\Log\LoggerInterface::class)
            );
        });

        $this->app->bind(ExecutionStore::class, function ($app) {
            return $app->make(ExecutionRepository::class);
        });

        $this->app->singleton(ExecutionStatusResolver::class, function ($app) {
            return new ExecutionStatusResolver(
                $app->make(ExecutionStore::class),
                $app->make(CheckpointManager::class)
            );
        });

        $this->app->bind(RouterResolverInterface::class, function ($app) {
            return new RoutingEngine($app);
        });

        $this->app->bind(ParallelExecutorInterface::class, function ($app) {
            return new ParallelExecutionCoordinator(
                $app->make(RoutingEngine::class),
                $app->make(StateManager::class),
                $app->make(\Illuminate\Cache\CacheManager::class),
                $app->make(\Illuminate\Contracts\Bus\Dispatcher::class),
                $app->make(\Psr\Log\LoggerInterface::class),
                $app->make(ExecutionRepository::class),
                $app->make(Dispatcher::class),
                $app,
                $app->make(CheckpointManager::class),
                config('agenticgraph.middleware.global'),
                static fn () => $app->bound(\Shahzad\AgenticGraph\Tracing\TraceContext::class)
                    ? $app->make(\Shahzad\AgenticGraph\Tracing\TraceContext::class)
                    : null,
                static fn () => $app->bound(\Shahzad\AgenticGraph\Execution\ExecutionContext::class)
                    ? $app->make(\Shahzad\AgenticGraph\Execution\ExecutionContext::class)
                    : null,
                static fn ($job) => dispatch($job)
            );
        });

        $this->app->bind(ParallelExecutionCoordinator::class, fn ($app) => $app->make(ParallelExecutorInterface::class));

        $this->app->bind(InterruptServiceInterface::class, function ($app) {
            $resumer = new class($app) implements ExecutionResumer
            {
                public function __construct(
                    private readonly \Illuminate\Contracts\Container\Container $container
                ) {}

                public function __invoke(
                    \Shahzad\AgenticGraph\Graph\Graph $graph,
                    \Shahzad\AgenticGraph\Contracts\StateContract $state,
                    string $startNode,
                    string $executionId,
                    bool $useBatch = false,
                    ?\Shahzad\AgenticGraph\Execution\LoopMetadata $loopMetadata = null
                ): \Shahzad\AgenticGraph\Contracts\StateContract {
                    return $this->container
                        ->make(\Shahzad\AgenticGraph\Execution\GraphExecutor::class)
                        ->executeFromNode($graph, $state, $startNode, $executionId, $useBatch, $loopMetadata);
                }
            };

            $handlerResolver = new class($app) implements InterruptHandlerResolver
            {
                public function __construct(
                    private readonly \Illuminate\Contracts\Container\Container $container
                ) {}

                public function __invoke(): ?\Shahzad\AgenticGraph\Contracts\InterruptHandlerInterface
                {
                    $handler = config('agenticgraph.interrupts.handler');
                    if (! $handler) {
                        return null;
                    }

                    $instance = is_string($handler) ? $this->container->make($handler) : $handler;
                    if (! $instance instanceof \Shahzad\AgenticGraph\Contracts\InterruptHandlerInterface) {
                        return null;
                    }

                    return $instance;
                }
            };

            $traceContextProvider = new class($app) implements TraceContextProvider
            {
                public function __construct(
                    private readonly \Illuminate\Contracts\Container\Container $container
                ) {}

                public function __invoke(): ?\Shahzad\AgenticGraph\Tracing\TraceContext
                {
                    return $this->container->bound(TraceContext::class)
                        ? $this->container->make(TraceContext::class)
                        : null;
                }
            };

            return new InterruptManager(
                $app->make(Dispatcher::class),
                $app->make(ExecutionRepository::class),
                $app->make(CheckpointManager::class),
                $app->make(StorageInterface::class),
                $app->make(\Shahzad\AgenticGraph\Graph\GraphRegistry::class),
                $app->make(\Psr\Log\LoggerInterface::class),
                $app,
                $resumer,
                $handlerResolver,
                $traceContextProvider,
                (bool) config('agenticgraph.interrupts.persistence_enabled', true),
                (bool) config('agenticgraph.interrupts.broadcast_enabled', true),
                (int) config('agenticgraph.interrupts.default_expiry_days', 7),
                (bool) config('agenticgraph.logging.enabled', true),
                $app->bound(PulseExporter::class) ? $app->make(PulseExporter::class) : null
            );
        });

        $this->app->bind(StreamStoreManager::class, function ($app) {
            return new StreamStoreManager($app);
        });

        $this->app->bind(StreamStoreInterface::class, function ($app) {
            return $app->make(StreamStoreManager::class)->store();
        });
        $this->app->bind(StreamSinkFactory::class, function ($app) {
            $rateLimitEnabled = (bool) config('agenticgraph.streaming.rate_limit.enabled', false);
            $rateLimitMaxEvents = (int) config('agenticgraph.streaming.rate_limit.max_events_per_second', 100);
            $retentionEnabled = (int) config('agenticgraph.streaming.retention.max_events_per_stream', 0) > 0
                || (int) config('agenticgraph.streaming.retention.max_age_hours', 0) > 0;

            return new StreamSinkFactory(
                $app->make(Broadcaster::class),
                $app->make(StreamStoreInterface::class),
                $rateLimitEnabled,
                $rateLimitMaxEvents,
                $retentionEnabled,
                new RetentionPolicyFactory,
                new StreamObserverFactory,
                $app->make(StreamErrorHandler::class)
            );
        });
        $this->app->bind(StreamingExecutorInterface::class, StreamingExecutionCoordinator::class);

        // Register tracing instrumentation if tracing is enabled
        if (config('agenticgraph.tracing.enabled', false)) {
            $this->registerTracing();
        }

        if (config('agenticgraph.streaming.routes.enabled', true)) {
            Route::middleware(config('agenticgraph.streaming.routes.middleware', ['api']))
                ->prefix(config('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams'))
                ->group(function (): void {
                    Route::get('/{streamId}', [StreamController::class, 'stream'])
                        ->name(\Shahzad\AgenticGraph\Streaming\StreamDescriptor::ROUTE_NAME);
                });
        }
    }

    /**
     * Register tracing instrumentation (lazy-loaded only when tracing is enabled)
     */
    private function registerTracing(): void
    {
        // Register event subscribers
        Event::subscribe(TracingListener::class);
        Event::subscribe(\Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation::class);

        // Register database instrumentation as singleton
        $this->app->singleton(\Shahzad\AgenticGraph\Tracing\Instrumentation\DatabaseInstrumentation::class, function ($app) {
            return new \Shahzad\AgenticGraph\Tracing\Instrumentation\DatabaseInstrumentation(
                $app[PulseExporter::class],
                (float) config('agenticgraph.tracing.db.slow_threshold_ms', 1000)
            );
        });

        // Register the database query listener
        $this->app[\Shahzad\AgenticGraph\Tracing\Instrumentation\DatabaseInstrumentation::class]->register($this->app['events']);
    }
}
