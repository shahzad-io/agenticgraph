<?php

function configWithEnv(string $key, ?string $value): array
{
    $value === null ? putenv($key) : putenv("{$key}={$value}");

    try {
        return require __DIR__.'/../../../config/agenticgraph.php';
    } finally {
        putenv($key);
    }
}

it('reads stream route middleware from AGENTICGRAPH_STREAMING_MIDDLEWARE', function (?string $env, array $expected) {
    expect(configWithEnv('AGENTICGRAPH_STREAMING_MIDDLEWARE', $env)['streaming']['routes']['middleware'])->toBe($expected);
})->with([
    'unset' => [null, ['api']],
    'empty' => ['', ['api']],
    'only commas' => [' , ,', ['api']],
    'single' => ['web', ['web']],
    'trimmed list' => [' api , auth:sanctum ', ['api', 'auth:sanctum']],
    'throttle parameters stay together' => ['api,throttle:5,1', ['api', 'throttle:5,1']],
    'middleware after throttle' => ['api,throttle:60,1,auth', ['api', 'throttle:60,1', 'auth']],
]);

it('has no dead interrupts.auto_cleanup_days setting', function () {
    expect(configWithEnv('AGENTICGRAPH_STREAMING_MIDDLEWARE', null)['interrupts'])->not->toHaveKey('auto_cleanup_days')
        ->and(config('agenticgraph.interrupts'))->not->toHaveKey('auto_cleanup_days');
});
