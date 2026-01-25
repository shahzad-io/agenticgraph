<?php

namespace Shahzad\AgenticGraph\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Shahzad\AgenticGraph\AgenticGraphServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Shahzad\\AgenticGraph\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function tearDown(): void
    {
        // Reset tracing context to prevent state leakage between tests
        if (class_exists(\Shahzad\AgenticGraph\Tracing\TraceContext::class)) {
            \Shahzad\AgenticGraph\Tracing\TraceContext::reset();
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        $providers = [AgenticGraphServiceProvider::class];
        if (class_exists(\Prism\Prism\PrismServiceProvider::class)) {
            $providers[] = \Prism\Prism\PrismServiceProvider::class;
        }

        return $providers;
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('agenticgraph.storage', 'database');
        config()->set('agenticgraph.tracing.enabled', false); // Disable tracing in tests by default
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file', [
            'driver' => 'file',
            'path' => storage_path('framework/cache'),
        ]);
        foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__.'/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }
    }
}
