<?php

namespace Shahzad\AgenticGraph;

use Shahzad\AgenticGraph\Commands\AgenticGraphCommand;
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
}
