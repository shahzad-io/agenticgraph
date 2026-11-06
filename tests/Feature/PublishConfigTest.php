<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Shahzad\AgenticGraph\AgenticGraphServiceProvider;

/** Publish paths are static on ServiceProvider; boot the package again with the files as the test left them. */
function rebootForPublish(): void
{
    (function (): void {
        static::$publishes = [];
        static::$publishGroups = [];
    })->bindTo(null, ServiceProvider::class)();

    $provider = new AgenticGraphServiceProvider(app());
    $provider->register();
    $provider->boot();
}

beforeEach(function () {
    $this->published = [config_path('agenticgraph.php'), config_path('model_pricing.php'), config_path('model_pricing_overrides.php')];
    foreach ($this->published as $file) {
        @unlink($file);
    }
});

afterEach(function () {
    foreach ($this->published as $file) {
        @unlink($file);
    }
});

it('never overwrites config/model_pricing_overrides.php, even with --force', function () {
    file_put_contents(config_path('model_pricing_overrides.php'), "<?php return ['models' => ['mine/model' => ['input' => 1.0, 'output' => 2.0]]];\n");
    file_put_contents(config_path('model_pricing.php'), "<?php return [];\n");
    $mine = file_get_contents(config_path('model_pricing_overrides.php'));
    rebootForPublish();

    Artisan::call('vendor:publish', ['--tag' => 'agenticgraph-config', '--force' => true]);

    expect(file_get_contents(config_path('model_pricing_overrides.php')))->toBe($mine)
        ->and(file_get_contents(config_path('model_pricing.php')))->not->toBe("<?php return [];\n")
        ->and(is_file(config_path('agenticgraph.php')))->toBeTrue();
});

it('publishes config/model_pricing_overrides.php once, when it is missing', function () {
    rebootForPublish();

    Artisan::call('vendor:publish', ['--tag' => 'agenticgraph-config']);

    expect(file_get_contents(config_path('model_pricing_overrides.php')))
        ->toBe(file_get_contents(dirname(__DIR__, 2).'/config/model_pricing_overrides.php'));
});

it('keeps the package overrides in config without a published file', function () {
    expect(config('model_pricing_overrides.models'))->toHaveKey('openai/gpt-6-luna');
});
