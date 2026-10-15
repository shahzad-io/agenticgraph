<?php

namespace Shahzad\AgenticGraph\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Prism\PrismServiceProvider;
use Shahzad\AgenticGraph\AgenticGraphServiceProvider;
use Shahzad\AgenticGraph\Tracing\TraceContext;

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
        app()->forgetInstance(TraceContext::class);

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        $providers = [AgenticGraphServiceProvider::class];
        if (class_exists(PrismServiceProvider::class)) {
            $providers[] = PrismServiceProvider::class;
        }

        return $providers;
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('agenticgraph.storage', 'database');
        config()->set('agenticgraph.tracing.enabled', false); // Disable tracing in tests by default
        config()->set('agenticgraph.prism.model', 'openai:gpt-4o-mini'); // the package has no default model
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
        foreach (File::allFiles(__DIR__.'/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }
    }
}
