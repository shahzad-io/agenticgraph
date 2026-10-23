<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptManager;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Facades\Trace;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\PulseExporter;

class StateCaptureExporter extends PulseExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}

class ScProfileNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state
            ->set('email', 'maria.garcia@example.com')
            ->set('question', 'changed question text')
            ->set('profile', ['name' => 'Maria', 'address' => ['city' => 'Lisbon']])
            ->set('long', str_repeat('a', 5000))
            ->set('nested', ['blob' => str_repeat('b', 5000)])
            ->set('small', ['ok' => true]);
    }
}

class ScBoomNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return Trace::span('sc.custom', fn () => throw new RuntimeException('secret failure for maria.garcia@example.com'));
    }
}

function scExporter(array $capture = []): StateCaptureExporter
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
        'agenticgraph.tracing.api_key' => 'ap_test_state_capture',
    ]);
    foreach ($capture as $key => $value) {
        config(["agenticgraph.tracing.capture.{$key}" => $value]);
    }
    // Console context: flush sends synchronously.
    app()->forgetInstance('request');

    $exporter = new StateCaptureExporter;
    app()->instance(TracerExporter::class, $exporter);
    app()->instance(PulseExporter::class, $exporter);
    foreach ([ExecutionContextManager::class, InterruptManager::class, ParallelExecutionCoordinator::class, ExecutionCoordinator::class, GraphExecutor::class] as $service) {
        app()->forgetInstance($service);
    }

    return $exporter;
}

function scGraph(bool $fail = false): Graph
{
    $builder = GraphBuilder::create($fail ? 'sc-fail' : 'sc-ok')->addNode('profile', ScProfileNode::class)->setEntryPoint('profile');
    if ($fail) {
        return $builder->addNode('boom', ScBoomNode::class)->addEdge('profile', 'boom')->addExitPoint('boom')->build();
    }

    return $builder->addExitPoint('profile')->build();
}

/**
 * @return array<string, mixed>
 */
function scRun(StateCaptureExporter $exporter, bool $fail = false): array
{
    $run = fn () => app(GraphExecutor::class)->execute(scGraph($fail), new State(['question' => 'secret question text', 'keep' => 1]));
    $fail ? expect($run)->toThrow(RuntimeException::class) : $run();

    expect($exporter->sent)->toHaveCount(1);

    return $exporter->sent[0];
}

/**
 * @return array<string, mixed>
 */
function scNodeSpan(array $payload, string $node): array
{
    return array_values(array_filter($payload['spans'], fn (array $s): bool => $s['node_name'] === $node))[0];
}

it('defaults to keys: top-level key names and value types, no values and no nested keys', function () {
    expect(config('agenticgraph.tracing.capture.state'))->toBe('keys');
    $payload = scRun(scExporter());
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect(scNodeSpan($payload, 'profile')['state_diff']['values'])->toBe([
        'question' => ['type' => 'changed', 'value_type' => 'string'],
        'email' => ['type' => 'added', 'value_type' => 'string'],
        'profile' => ['type' => 'added', 'value_type' => 'array'],
        'long' => ['type' => 'added', 'value_type' => 'string'],
        'nested' => ['type' => 'added', 'value_type' => 'array'],
        'small' => ['type' => 'added', 'value_type' => 'array'],
    ])
        ->and($payload['trace'])->not->toHaveKeys(['initial_state', 'final_state'])
        ->and($json)->not->toContain('maria.garcia', 'secret question', 'changed question', 'Maria', 'address', 'Lisbon', 'blob', 'aaaa');
});

it('keys is also the listener fallback when an older published config has no state key', function () {
    $exporter = scExporter();
    config()->set('agenticgraph.tracing.capture', []);

    expect(scNodeSpan(scRun($exporter), 'profile')['state_diff']['values']['email'])->toBe(['type' => 'added', 'value_type' => 'string']);
});

it('diff keeps the values of changed keys, as before', function () {
    $values = scNodeSpan(scRun(scExporter(['state' => 'diff'])), 'profile')['state_diff']['values'];

    expect($values['email'])->toBe(['type' => 'added', 'value' => 'maria.garcia@example.com'])
        ->and($values['question'])->toBe(['type' => 'changed', 'old' => 'secret question text', 'new' => 'changed question text'])
        ->and($values['profile'])->toBe(['type' => 'added', 'value' => ['name' => 'Maria', 'address' => ['city' => 'Lisbon']]])
        ->and($values)->not->toHaveKey('keep');
});

it('full keeps the output state per node and the initial and final state', function () {
    $payload = scRun(scExporter(['state' => 'full']));

    expect(scNodeSpan($payload, 'profile')['state_diff']['values']['email'])->toBe('maria.garcia@example.com')
        ->and($payload['trace']['initial_state'])->toBe(['question' => 'secret question text', 'keep' => 1])
        ->and($payload['trace']['final_state']['email'])->toBe('maria.garcia@example.com');
});

it('error_only captures the state at the failure on the failed trace', function () {
    $payload = scRun(scExporter(['state' => 'error_only']), fail: true);

    expect($payload['trace']['status'])->toBe('failed')
        ->and($payload['trace']['final_state']['email'] ?? null)->toBe('maria.garcia@example.com')
        ->and($payload['trace']['final_state']['keep'] ?? null)->toBe(1);
});

it('error_only sends keys on success: no state values and no initial or final state', function () {
    $payload = scRun(scExporter(['state' => 'error_only']));

    expect(scNodeSpan($payload, 'profile')['state_diff']['values']['email'] ?? null)->toBe(['type' => 'added', 'value_type' => 'string'])
        ->and($payload['trace'])->not->toHaveKeys(['initial_state', 'final_state'])
        ->and(json_encode($payload))->not->toContain('maria.garcia');
});

it('full also captures the state at the failure on a failed trace', function () {
    $payload = scRun(scExporter(['state' => 'full']), fail: true);

    expect($payload['trace']['final_state']['email'] ?? null)->toBe('maria.garcia@example.com');
});

it('max_length bounds state values in diff', function () {
    $values = scNodeSpan(scRun(scExporter(['state' => 'diff', 'max_length' => 100])), 'profile')['state_diff']['values'];

    expect(strlen($values['long']['value']))->toBe(100)
        ->and($values['long']['value'])->toEndWith('[...truncated]')
        ->and($values['nested']['value'])->toBeString()
        ->and(strlen($values['nested']['value']))->toBe(100)
        ->and($values['small']['value'])->toBe(['ok' => true])
        ->and($values['email']['value'])->toBe('maria.garcia@example.com');
});

it('max_length bounds state values in full, per node and in the initial and final state', function () {
    $exporter = scExporter(['state' => 'full', 'max_length' => 100]);
    app(GraphExecutor::class)->execute(scGraph(), new State(['question' => str_repeat('q', 5000)]));
    $payload = $exporter->sent[0];

    expect(strlen(scNodeSpan($payload, 'profile')['state_diff']['values']['long']))->toBe(100)
        ->and(strlen($payload['trace']['initial_state']['question']))->toBe(100)
        ->and(strlen($payload['trace']['final_state']['long']))->toBe(100)
        ->and(strlen($payload['trace']['final_state']['nested']))->toBe(100)
        ->and($payload['trace']['final_state']['small'])->toBe(['ok' => true]);
});

it('capture.errors=class sends the exception class and never its message', function () {
    $payload = scRun(scExporter(['errors' => 'class']), fail: true);
    $error = ['type' => RuntimeException::class];

    expect($payload['trace']['error'])->toBe($error)
        ->and(scNodeSpan($payload, 'boom')['error'])->toBe($error)
        ->and(scNodeSpan($payload, 'sc-fail')['error'])->toBe($error)
        ->and(scNodeSpan($payload, 'sc.custom')['error'])->toBe($error)
        ->and(json_encode($payload))->not->toContain('secret failure');
});

it('capture.errors defaults to message, as before', function () {
    expect(config('agenticgraph.tracing.capture.errors'))->toBe('message');
    $payload = scRun(scExporter(), fail: true);

    expect($payload['trace']['error'])->toBe(['type' => RuntimeException::class, 'message' => 'secret failure for maria.garcia@example.com'])
        ->and(scNodeSpan($payload, 'sc.custom')['error']['message'])->toBe('secret failure for maria.garcia@example.com');
});
