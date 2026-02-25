<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Shahzad\AgenticGraph\Adapters\PrismFacadeClient;
use Shahzad\AgenticGraph\Commands\AgenticGraphCommand;
use Shahzad\AgenticGraph\Contracts\PrismClient;
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;
use Shahzad\AgenticGraph\Execution\ExecutionStatusResolver;
use Shahzad\AgenticGraph\Execution\ExecutionStore;
use Shahzad\AgenticGraph\Http\Controllers\StreamController;
use Shahzad\AgenticGraph\State\StateManager;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Storage\RedisStorage;
use Shahzad\AgenticGraph\Streaming\Contracts\StreamStoreInterface;
use Shahzad\AgenticGraph\Streaming\Store\StreamStoreManager;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
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
                (int) config('agenticgraph.checkpoint.max_state_size', 10485760)
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

        $this->app->bind(StreamStoreManager::class, function ($app) {
            return new StreamStoreManager($app);
        });

        $this->app->bind(StreamStoreInterface::class, function ($app) {
            return $app->make(StreamStoreManager::class)->store();
        });

        // Register tracing instrumentation if tracing is enabled
        if (config('agenticgraph.tracing.enabled', false)) {
            $this->registerTracing();
        }

        if (config('agenticgraph.streaming.routes.enabled', true)) {
            Route::middleware(config('agenticgraph.streaming.routes.middleware', ['api']))
                ->prefix(config('agenticgraph.streaming.routes.prefix', 'agentic-graph/streams'))
                ->group(function (): void {
                    Route::get('/{streamId}', [StreamController::class, 'stream']);
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
