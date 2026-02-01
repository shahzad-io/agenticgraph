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
        // Clear any TraceContext instance from container to prevent state leakage between tests
        app()->forgetInstance(\Shahzad\AgenticGraph\Tracing\TraceContext::class);

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
        // Use array cache driver for tests (supports object storage without serialization)
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__.'/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }
    }
}
