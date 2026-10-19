<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Shahzad\AgenticGraph\Adapters\PrismFacadeClient;
use Shahzad\AgenticGraph\Commands\AgenticGraphCommand;
use Shahzad\AgenticGraph\Console\CheckpointCleanupCommand;
use Shahzad\AgenticGraph\Console\CheckpointDeleteCommand;
use Shahzad\AgenticGraph\Console\CheckpointListCommand;
use Shahzad\AgenticGraph\Console\CheckpointResumeCommand;
use Shahzad\AgenticGraph\Console\ExecuteGraphCommand;
use Shahzad\AgenticGraph\Console\ExecutionsListCommand;
use Shahzad\AgenticGraph\Console\InterruptExpireCommand;
use Shahzad\AgenticGraph\Console\ListGraphsCommand;
use Shahzad\AgenticGraph\Console\SyncModelPricingCommand;
use Shahzad\AgenticGraph\Console\VisualizeGraphCommand;
use Shahzad\AgenticGraph\Contracts\InterruptHandlerInterface;
use Shahzad\AgenticGraph\Contracts\InterruptServiceInterface;
use Shahzad\AgenticGraph\Contracts\ParallelExecutorInterface;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Contracts\RouterResolverInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Contracts\StorageInterface;
use Shahzad\AgenticGraph\Contracts\StreamingExecutorInterface;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionResumer;
use Shahzad\AgenticGraph\Execution\ExecutionStatusResolver;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptHandlerResolver;
use Shahzad\AgenticGraph\Execution\InterruptManager;
use Shahzad\AgenticGraph\Execution\LoopMetadata;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\RoutingEngine;
use Shahzad\AgenticGraph\Execution\TraceContextProvider;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Storage\RedisStorage;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\RetentionPolicyFactory;
use Shahzad\AgenticGraph\Streaming\Store\StreamStoreManager;
use Shahzad\AgenticGraph\Streaming\StreamDescriptor;
use Shahzad\AgenticGraph\Streaming\StreamErrorHandler;
use Shahzad\AgenticGraph\Streaming\StreamingExecutionCoordinator;
use Shahzad\AgenticGraph\Streaming\StreamObserverFactory;
use Shahzad\AgenticGraph\Streaming\StreamSinkFactory;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClockFactory;
use Shahzad\AgenticGraph\Tracing\Exporters\FileExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\LogExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\NullExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\OtlpExporter;
use Shahzad\AgenticGraph\Tracing\Instrumentation\DatabaseInstrumentation;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\ModelPricingCalculator;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceContextFactory;
use Shahzad\AgenticGraph\Tracing\Tracer;
use Shahzad\AgenticGraph\Tracing\TracerExporterFactory;
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
            ->hasConfigFile(['agenticgraph', 'model_pricing', 'model_pricing_overrides'])
            ->hasViews()
            ->hasMigration('create_agenticgraph_executions_table')
            ->hasMigration('create_agenticgraph_checkpoints_table')
            ->hasMigration('create_agenticgraph_stream_events_table')
            ->hasMigration('create_agenticgraph_interrupts_table')
            // Upgrades for apps that published 0.1.0, in this order; each is a no-op on a fresh install.
            ->hasMigration('zzzz_add_checkpoint_node_count_to_agenticgraph_executions_table')
            ->hasMigration('zzzz_add_execution_options_to_agenticgraph_executions_table')
            ->hasMigration('zzzz_drop_checkpoint_tree_columns_from_agenticgraph_checkpoints_table')
            ->hasMigration('zzzz_add_stream_columns_to_agenticgraph_executions_table')
            ->hasCommand(AgenticGraphCommand::class)
            ->hasCommand(ListGraphsCommand::class)
            ->hasCommand(VisualizeGraphCommand::class)
            ->hasCommand(ExecuteGraphCommand::class)
            ->hasCommand(CheckpointListCommand::class)
            ->hasCommand(CheckpointResumeCommand::class)
            ->hasCommand(CheckpointDeleteCommand::class)
            ->hasCommand(CheckpointCleanupCommand::class)
            ->hasCommand(ExecutionsListCommand::class)
            ->hasCommand(InterruptExpireCommand::class)
            ->hasCommand(SyncModelPricingCommand::class);
    }

    public function packageBooted(): void
    {
        $this->app->bind(PrismClient::class, PrismFacadeClient::class);

        // Bind StorageInterface to the configured storage driver
        $this->app->bind(StorageInterface::class, function ($app) {
            return match (config('agenticgraph.storage', 'database')) {
                'redis' => $app->make(RedisStorage::class),
                'file' => $app->make(FileStorage::class),
                default => $app->make(DatabaseStorage::class),
            };
        });

        // Per-worker in-memory graph registry (Octane-safe, no serialization)
        $this->app->singleton(GraphRegistry::class, function () {
            return new GraphRegistry;
        });

        // Register tracer exporters (open-core: contract + pulse premium + OSS fallbacks)
        $this->app->singleton(PulseExporter::class, fn () => new PulseExporter);
        $this->app->singleton(LogExporter::class, fn () => new LogExporter);
        $this->app->singleton(FileExporter::class, fn () => new FileExporter);
        $this->app->singleton(OtlpExporter::class, fn () => new OtlpExporter);
        $this->app->singleton(NullExporter::class, fn () => new NullExporter);
        $this->app->singleton(TracerExporter::class, fn ($app) => TracerExporterFactory::make($app));
        $this->app->singleton(Tracer::class, fn () => new Tracer);
        $this->app->singleton(ModelPricingCalculator::class);

        // Register StateManager as singleton with dependencies
        $this->app->singleton(StateManager::class, function ($app) {
            $storage = match (config('agenticgraph.storage', 'database')) {
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

        $this->app->bind(NodeMiddlewarePipeline::class, function ($app) {
            return new NodeMiddlewarePipeline(
                $app,
                $app->make(Dispatcher::class),
                config('agenticgraph.middleware.global', []),
                static fn (): ?TraceContext => TraceContext::tryGet(),
            );
        });

        $this->app->singleton(CheckpointManager::class, function ($app) {
            $storage = match (config('agenticgraph.storage', 'database')) {
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
                null,
                $app->bound(HttpClientInstrumentation::class)
                    ? $app->make(HttpClientInstrumentation::class)
                    : null,
                $app->make(TraceContextFactory::class),
                $app->make(ExecutionClockFactory::class),
                $app,
                $app->make(LoggerInterface::class)
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
                $app->make(CacheManager::class),
                $app->make(\Illuminate\Contracts\Bus\Dispatcher::class),
                $app->make(LoggerInterface::class),
                $app->make(ExecutionRepository::class),
                $app->make(Dispatcher::class),
                $app,
                $app->make(CheckpointManager::class),
                config('agenticgraph.middleware.global', []),
                static fn () => $app->bound(TraceContext::class)
                    ? $app->make(TraceContext::class)
                    : null,
                static fn () => $app->bound(ExecutionContext::class)
                    ? $app->make(ExecutionContext::class)
                    : null,
                static fn ($job) => dispatch($job)
            );
        });

        $this->app->bind(ParallelExecutionCoordinator::class, fn ($app) => $app->make(ParallelExecutorInterface::class));

        $this->app->bind(InterruptServiceInterface::class, function ($app) {
            $resumer = new class($app) implements ExecutionResumer
            {
                public function __construct(
                    private readonly Container $container
                ) {}

                public function __invoke(
                    Graph $graph,
                    StateContract $state,
                    string $startNode,
                    string $executionId,
                    bool $useBatch = false,
                    ?LoopMetadata $loopMetadata = null,
                    bool $skipInterruptGateForEntry = false
                ): StateContract {
                    return $this->container
                        ->make(GraphExecutor::class)
                        ->executeFromNode(
                            $graph,
                            $state,
                            $startNode,
                            $executionId,
                            $useBatch,
                            $loopMetadata,
                            $skipInterruptGateForEntry
                        );
                }
            };

            $handlerResolver = new class($app) implements InterruptHandlerResolver
            {
                public function __construct(
                    private readonly Container $container
                ) {}

                public function __invoke(): ?InterruptHandlerInterface
                {
                    $handler = config('agenticgraph.interrupts.handler', null);
                    if (! $handler) {
                        return null;
                    }

                    $instance = is_string($handler) ? $this->container->make($handler) : $handler;
                    if (! $instance instanceof InterruptHandlerInterface) {
                        return null;
                    }

                    return $instance;
                }
            };

            $traceContextProvider = new class($app) implements TraceContextProvider
            {
                public function __construct(
                    private readonly Container $container
                ) {}

                public function __invoke(): ?TraceContext
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
                $app->make(GraphRegistry::class),
                $app->make(LoggerInterface::class),
                $app,
                $resumer,
                $handlerResolver,
                $traceContextProvider,
                (bool) config('agenticgraph.interrupts.persistence_enabled', true),
                (bool) config('agenticgraph.interrupts.broadcast_enabled', true),
                (int) config('agenticgraph.interrupts.default_expiry_days', 7),
                (bool) config('agenticgraph.logging.enabled', true),
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

        // Always registered: each handler checks tracing.enabled when its event fires, so a runtime change applies.
        $this->registerTracing();

        if (config('agenticgraph.streaming.routes.enabled', true)) {
            Route::middleware(config('agenticgraph.streaming.routes.middleware', ['api']))
                ->prefix(config('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams'))
                ->group(function (): void {
                    Route::get('/{streamId}', [StreamController::class, 'stream'])
                        ->name(StreamDescriptor::ROUTE_NAME);
                });
        }
    }

    /**
     * Register tracing instrumentation. With tracing off each handler returns after a config read.
     */
    private function registerTracing(): void
    {
        // Register event subscribers
        Event::subscribe(new TracingListener);
        // Singleton so RequestSending and ResponseReceived share the stored request starts.
        $this->app->singleton(HttpClientInstrumentation::class, fn () => new HttpClientInstrumentation);
        Event::subscribe(HttpClientInstrumentation::class);

        // Register database instrumentation as singleton
        $this->app->singleton(DatabaseInstrumentation::class, function ($app) {
            return new DatabaseInstrumentation(
                slowThresholdMs: (float) config('agenticgraph.tracing.db.slow_threshold_ms', 1000)
            );
        });

        // Register the database query listener
        $this->app[DatabaseInstrumentation::class]->register($this->app['events']);
    }
}
