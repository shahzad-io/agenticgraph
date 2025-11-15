<?php

namespace Shahzad\AgenticGraph;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Shahzad\AgenticGraph\Commands\AgenticGraphCommand;

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
            ->hasMigration('create_agenticgraph_table')
            ->hasCommand(AgenticGraphCommand::class);
    }
}
