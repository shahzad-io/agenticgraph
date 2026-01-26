<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph;

use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Adapters\PrismFacadeClient;
use Shahzad\AgenticGraph\Commands\AgenticGraphCommand;
use Shahzad\AgenticGraph\Contracts\PrismClient;
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
            ->hasMigration('create_agenticgraph_checkpoints_table')
            ->hasMigration('create_agenticgraph_executions_table')
            ->hasMigration('improve_agenticgraph_executions_table')
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

        // Register PulseExporter as singleton
        $this->app->singleton(PulseExporter::class, function ($app) {
            return new PulseExporter;
        });

        // Register tracing event subscriber if tracing is enabled
        if (config('agenticgraph.tracing.enabled', false)) {
            Event::subscribe(TracingListener::class);
        }
    }
}
