<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Execution\ExecutionContext;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptManager;
use Shahzad\AgenticGraph\Execution\NodeMiddlewarePipeline;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Facades\Trace;
use Shahzad\AgenticGraph\Graph\Annotate;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Graph\InvalidGraphException;
use Shahzad\AgenticGraph\Jobs\ExecuteNodeJob;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Streaming\StreamOptions;
use Shahzad\AgenticGraph\Streaming\StreamResumption;
use Shahzad\AgenticGraph\Testing\Llm;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\Exporters\OtlpExporter;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\TraceSegmentMerger;

class AnnotationsExporter extends PulseExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    protected function send(array $payload): void
    {
        $this->sent[] = $payload;
    }
}

#[Annotate('Look up the order', description: 'Looked up order {order_lookup.order_id}')]
class AnLookupNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state
            ->set('order_lookup', ['order_id' => 3, 'status' => 'processing', 'total' => 210.0, 'card' => 'SECRET-CARD-4242'])
            ->set('items', [['name' => 'Mug'], ['name' => 'Plate']])
            ->set('email', 'maria.garcia@example.com');
    }
}

class AnPlainNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('answer', 'done');
    }
}

class AnToolNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $attributes = ['tool.type' => 'prism'];
        Trace::span('lookup_order', fn () => 'ok', $attributes, 'tool');
        Trace::span('send_email', fn () => 'ok', $attributes, 'tool');
        $context = TraceContext::tryGet();
        event(new ToolCallCaptured($context->getExecutionId(), $context->getSpanId(), 'lookup_order', 'call_1', 'provider', 10, null, null, new DateTimeImmutable));

        return $state->set('tools_ran', true);
    }
}

class AnBoomNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        throw new RuntimeException('an boom');
    }

    public function shouldRetry(Throwable $exception): bool
    {
        return false;
    }
}

class AnFlakyNode extends AbstractNode
{
    public static int $calls = 0;

    public function execute(StateContract $state): StateContract
    {
        if (++self::$calls === 1) {
            throw new RuntimeException('first attempt fails');
        }

        return $state->set('loops', $state->get('loops', 0) + 1);
    }

    public function getRetryDelay(): int
    {
        return 0;
    }
}

class AnValuesNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state
            ->set('list', ['a' => 1, 'b' => [true]])
            ->set('json', new AnJson)
            ->set('object', new AnOpaque)
            ->set('flag', false)
            ->set('long', str_repeat('x', 3000))
            ->set('nothing', null);
    }
}

class AnJson implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return ['kind' => 'json'];
    }
}

class AnOpaque
{
    public string $secret = 'opaque-secret';
}

/** Stored and checkpointed like any object; only a placeholder lookup into it throws. */
class AnThrowingAccess implements ArrayAccess
{
    public function offsetExists(mixed $offset): bool
    {
        throw new LogicException('cannot read');
    }

    public function offsetGet(mixed $offset): mixed
    {
        throw new LogicException('cannot read');
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}
}

class AnThrowingNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        return $state->set('bad', new AnThrowingAccess)->set('fine', 'yes');
    }
}

#[Annotate('Approve the refund', humanInput: ['approved', 'approval_comments', 'never_written'])]
class AnApprovalNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $order = $state->get('order_lookup', []);
        $order['status'] = $state->get('approved') ? 'refunded' : 'kept';

        return $state->set('order_lookup', $order);
    }

    public function shouldInterrupt(StateContract $state): bool
    {
        return ! $state->has('approved');
    }

    /** Writes state keys of its own; human_input reads the state, not the raw input. */
    public function onResume(StateContract $state, mixed $humanInput): StateContract
    {
        return $state
            ->set('approved', $humanInput['decision'] === 'yes')
            ->set('approval_comments', $humanInput['note'])
            ->set('reviewer_email', 'boss@example.com');
    }
}

#[Annotate(humanInput: 'approved')]
class AnBadAttributeNode extends AnPlainNode {}

function anExporter(array $capture = []): AnnotationsExporter
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
        'agenticgraph.tracing.api_key' => 'ap_test_annotations',
        'agenticgraph.checkpoint.enabled' => true,
    ]);
    foreach ($capture as $key => $value) {
        config(["agenticgraph.tracing.capture.{$key}" => $value]);
    }
    app()->forgetInstance('request');

    $exporter = new AnnotationsExporter;
    app()->instance(TracerExporter::class, $exporter);
    app()->instance(PulseExporter::class, $exporter);
    anForgetServices();

    return $exporter;
}

function anForgetServices(): void
{
    $services = [ExecutionContextManager::class, InterruptManager::class, ParallelExecutionCoordinator::class, ExecutionCoordinator::class, GraphExecutor::class];
    foreach ([...$services, Graph::class, TraceContext::class, ExecutionClock::class, ExecutionContext::class] as $service) {
        app()->forgetInstance($service);
    }
}

/**
 * @param  list<array{0: string, 1: class-string, 2?: array<string, mixed>}>  $nodes
 * @param  Closure(GraphBuilder): GraphBuilder|null  $declare
 */
function anGraph(string $name, array $nodes, ?Closure $declare = null): Graph
{
    $builder = GraphBuilder::create($name);
    foreach ($nodes as $node) {
        $builder->addNode($node[0], $node[1], ...($node[2] ?? []));
    }
    for ($i = 1; $i < count($nodes); $i++) {
        $builder->addEdge($nodes[$i - 1][0], $nodes[$i][0]);
    }
    $builder->setEntryPoint($nodes[0][0])->addExitPoint($nodes[count($nodes) - 1][0]);
    $graph = ($declare ? $declare($builder) : $builder)->build();
    app(GraphRegistry::class)->register($name, $graph);

    return $graph;
}

function anOrderGraph(string $name = 'an-order'): Graph
{
    return anGraph($name, [['lookup', AnLookupNode::class], ['answer', AnPlainNode::class, ['label' => 'Answer', 'description' => 'Sent {answer} for {items.0.name} {{ok}}']]], fn (GraphBuilder $b) => $b
        ->label('Order status')
        ->description('Answers order questions')
        ->outcome('Order {order_lookup.order_id} is {order_lookup.status}', ['order_lookup.total' => 'Total', 'order_lookup.status'])
        ->expectations(['read_only' => true, 'max_retries' => 2]));
}

/**
 * @return array<string, mixed>
 */
function anSpan(array $payload, string $node): array
{
    return array_values(array_filter($payload['spans'], fn (array $s): bool => $s['node_name'] === $node))[0];
}

/**
 * Ids, timestamps and durations replaced, so two runs compare by shape and values.
 *
 * @return array<int|string, mixed>
 */
function anNormalize(array $data): array
{
    $volatile = ['id', 'trace_id', 'parent_id', 'attempt_of', 'started_at', 'ended_at', 'duration_ms', 'active_ms', 'interrupted_at', 'resumed_at', 'checkpoint.id', 'span_id', 'interrupt_expires_at', 'interrupt.expires_at'];
    foreach ($data as $key => $value) {
        $data[$key] = in_array($key, $volatile, true) ? '#' : (is_array($value) ? anNormalize($value) : $value);
    }

    return $data;
}

function anJson(array $payloads): string
{
    return json_encode($payloads, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

// Build time

it('reads #[Annotate] from the node class, the fluent arguments, and lets the fluent form win per field', function () {
    $graph = anGraph('an-merge', [
        ['attr', AnLookupNode::class],
        ['fluent', AnPlainNode::class, ['label' => 'Fluent label', 'humanInput' => ['x']]],
        ['both', AnLookupNode::class, ['description' => 'Fluent description']],
        ['none', AnPlainNode::class],
    ]);

    expect($graph->annotations['nodes'])->toBe([
        'attr' => ['label' => 'Look up the order', 'description' => 'Looked up order {order_lookup.order_id}'],
        'fluent' => ['label' => 'Fluent label', 'human_input' => ['x']],
        'both' => ['description' => 'Fluent description', 'label' => 'Look up the order'],
    ]);
});

it('a graph without declarations carries no annotations', function () {
    expect(anGraph('an-empty', [['a', AnPlainNode::class]], fn (GraphBuilder $b) => $b->description('Has a description'))->annotations)->toBe([]);
});

it('rejects invalid declarations at build time with InvalidGraphException', function (Closure $declare, string $message) {
    expect(fn () => $declare(GraphBuilder::create('an-invalid')->addNode('a', AnPlainNode::class)->setEntryPoint('a')->addExitPoint('a'))->build())
        ->toThrow(InvalidGraphException::class, $message);
})->with([
    'node label over 60' => [fn (GraphBuilder $b) => $b->addNode('b', AnPlainNode::class, label: str_repeat('l', 61)), 'at most 60'],
    'node description over 200' => [fn (GraphBuilder $b) => $b->addNode('b', AnPlainNode::class, description: str_repeat('d', 201)), 'at most 200'],
    'graph label over 60' => [fn (GraphBuilder $b) => $b->label(str_repeat('g', 61)), 'at most 60'],
    'headline over 200' => [fn (GraphBuilder $b) => $b->outcome(str_repeat('h', 201)), 'at most 200'],
    'nine outcome fields' => [fn (GraphBuilder $b) => $b->outcome('Done', array_map(fn (int $i) => "f{$i}", range(1, 9))), 'at most 8'],
    'nine human input keys' => [fn (GraphBuilder $b) => $b->addNode('b', AnPlainNode::class, humanInput: array_map(fn (int $i) => "k{$i}", range(1, 9))), 'at most 8'],
    'filter in a placeholder' => [fn (GraphBuilder $b) => $b->addNode('b', AnPlainNode::class, description: 'Total {total|upper}'), 'invalid placeholder'],
    'method call in a placeholder' => [fn (GraphBuilder $b) => $b->outcome('Total {total()}'), 'invalid placeholder'],
    'unclosed brace' => [fn (GraphBuilder $b) => $b->addNode('b', AnPlainNode::class, label: 'Total {total'), 'invalid placeholder'],
    'stray closing brace' => [fn (GraphBuilder $b) => $b->outcome('Total total}'), "unmatched '}'"],
    'outcome field path' => [fn (GraphBuilder $b) => $b->outcome('Done', ['a b' => 'Label']), 'not a state path'],
    'human input key path' => [fn (GraphBuilder $b) => $b->addNode('b', AnPlainNode::class, humanInput: ['ok', 'not ok']), 'not a state path'],
    'unknown expectation' => [fn (GraphBuilder $b) => $b->expectations(['max_tokens' => 5]), "'max_tokens' is unknown"],
    'read_only not a bool' => [fn (GraphBuilder $b) => $b->expectations(['read_only' => 1]), 'must be a bool'],
    'negative max_retries' => [fn (GraphBuilder $b) => $b->expectations(['max_retries' => -1]), 'at least 0'],
    'max_loops as a float' => [fn (GraphBuilder $b) => $b->expectations(['max_loops' => 2.0]), 'must be an int'],
    'max_cost_usd as a string' => [fn (GraphBuilder $b) => $b->expectations(['max_cost_usd' => '0.10']), 'must be a number'],
    'unknown tool expectation' => [fn (GraphBuilder $b) => $b->toolExpectations('lookup', ['max_retries' => 1]), "'max_retries' is unknown"],
    'empty tool name' => [fn (GraphBuilder $b) => $b->toolExpectations(' ', ['read_only' => true]), 'Tool name cannot be empty'],
    'bad #[Annotate] on the class' => [fn (GraphBuilder $b) => $b->addNode('b', AnBadAttributeNode::class)->addEdge('a', 'b'), 'Invalid #[Annotate]'],
    'over-long #[Annotate] label' => [fn (GraphBuilder $b) => $b->addNode('b', AnLongLabelNode::class)->addEdge('a', 'b'), '#[Annotate] on AnLongLabelNode label is 61 characters'],
]);

#[Annotate(label: 'lllllllllllllllllllllllllllllllllllllllllllllllllllllllllllll')]
class AnLongLabelNode extends AnPlainNode {}

it('accepts escaped braces, dashes, digits and list indexes, and keeps max_cost_usd a float', function () {
    $graph = GraphBuilder::create('an-valid')
        ->addNode('a', AnPlainNode::class, description: '{{literal}} {a-b.0.c_d}')
        ->setEntryPoint('a')->addExitPoint('a')
        ->expectations(['max_cost_usd' => 1, 'max_loops' => 0])
        ->toolExpectations('lookup', ['read_only' => false])
        ->build();

    expect($graph->annotations['expectations'])->toBe(['max_cost_usd' => 1.0, 'max_loops' => 0])
        ->and($graph->annotations['tools'])->toBe(['lookup' => ['read_only' => false]]);
});

// Wire

it('an unannotated graph sends no annotations or human_input key, also with description() set', function () {
    $exporter = anExporter();
    app(GraphExecutor::class)->execute(anGraph('an-plain', [['a', AnPlainNode::class], ['b', AnToolNode::class]], fn (GraphBuilder $b) => $b->description('Desc')), new State([]));

    expect(anJson($exporter->sent))->not->toContain('"annotations"', '"human_input"', 'Desc');
});

it('keys mode sends the declared values and nothing else from the state', function () {
    expect(config('agenticgraph.tracing.capture.state'))->toBe('keys');
    $exporter = anExporter();
    app(GraphExecutor::class)->execute(anOrderGraph(), new State(['question' => 'secret question text']));

    $payload = $exporter->sent[0];
    expect($payload['trace']['annotations'])->toBe([
        'graph' => ['label' => 'Order status', 'description' => 'Answers order questions'],
        'outcome' => [
            'headline' => 'Order 3 is processing',
            'fields' => [
                ['path' => 'order_lookup.total', 'label' => 'Total', 'value' => 210.0],
                ['path' => 'order_lookup.status', 'value' => 'processing'],
            ],
            'partial' => false,
            'missing' => [],
        ],
        'expectations' => ['read_only' => true, 'max_retries' => 2],
    ])
        ->and(array_key_last($payload['trace']))->toBe('annotations')
        ->and(anSpan($payload, 'lookup')['annotations'])->toBe(['label' => 'Look up the order', 'description' => 'Looked up order 3', 'missing' => []])
        ->and(anSpan($payload, 'answer')['annotations'])->toBe(['label' => 'Answer', 'description' => 'Sent done for Mug {ok}', 'missing' => []])
        ->and(anJson($exporter->sent))->not->toContain('SECRET-CARD', 'maria.garcia', 'secret question', 'Plate');
});

it('renders a missing key as empty and lists it in missing', function () {
    $exporter = anExporter();
    $graph = anGraph('an-missing', [['a', AnPlainNode::class, ['description' => 'Got {answer} and {nope.deep} and {nope.deep}']]], fn (GraphBuilder $b) => $b
        ->outcome('Status {status}', ['answer', 'gone']));
    app(GraphExecutor::class)->execute($graph, new State([]));

    expect(anSpan($exporter->sent[0], 'a')['annotations'])->toBe(['description' => 'Got done and  and ', 'missing' => ['nope.deep']])
        ->and($exporter->sent[0]['trace']['annotations']['outcome'])->toBe([
            'headline' => 'Status ',
            'fields' => [['path' => 'answer', 'value' => 'done'], ['path' => 'gone', 'value' => null]],
            'partial' => false,
            'missing' => ['status', 'gone'],
        ]);
});

it('serializes values as state capture does: JSON, class names, bools and the size limit', function () {
    config(['agenticgraph.tracing.capture.max_length' => 100]);
    $exporter = anExporter();
    $graph = anGraph('an-values', [['v', AnValuesNode::class, ['description' => '{list}|{json}|{object}|{flag}|{nothing}|']]], fn (GraphBuilder $b) => $b
        ->outcome('{long}', ['list', 'json', 'object', 'flag', 'long', 'nothing']));
    app(GraphExecutor::class)->execute($graph, new State([]));

    $outcome = $exporter->sent[0]['trace']['annotations']['outcome'];
    expect(anSpan($exporter->sent[0], 'v')['annotations']['description'])->toBe('{"a":1,"b":[true]}|{"kind":"json"}|AnOpaque|false||')
        ->and($outcome['headline'])->toBe(str_repeat('x', 85).' [...truncated]')
        ->and(array_column($outcome['fields'], 'value'))->toBe(['{"a":1,"b":[true]}', '{"kind":"json"}', 'AnOpaque', false, str_repeat('x', 85).' [...truncated]', null])
        ->and($outcome['missing'])->toBe([])
        ->and(anJson($exporter->sent))->not->toContain('opaque-secret');
});

it('a rendering exception drops the declaration, records the class name, and never fails the run', function () {
    $exporter = anExporter();
    $graph = anGraph('an-throws', [['t', AnThrowingNode::class, ['label' => 'Fine {fine}', 'description' => 'Bad {bad.key}']]], fn (GraphBuilder $b) => $b
        ->label('Throwing')
        ->outcome('Bad {bad.key}')
        ->expectations(['read_only' => true]));

    $state = app(GraphExecutor::class)->execute($graph, new State([]));

    expect($state->get('fine'))->toBe('yes')
        ->and($exporter->sent[0]['trace']['status'])->toBe('completed')
        ->and(anSpan($exporter->sent[0], 't')['annotations'])->toBe(['annotation_error' => LogicException::class])
        ->and($exporter->sent[0]['trace']['annotations'])->toBe([
            'graph' => ['label' => 'Throwing'],
            'expectations' => ['read_only' => true],
            'annotation_error' => LogicException::class,
        ])
        ->and(anJson($exporter->sent))->not->toContain('cannot read');
});

it('a failed run sends the graph block, expectations and a null outcome', function () {
    $exporter = anExporter();
    $graph = anGraph('an-failed', [['lookup', AnLookupNode::class], ['boom', AnBoomNode::class, ['label' => 'Explode']]], fn (GraphBuilder $b) => $b
        ->label('Failing')
        ->outcome('Order {order_lookup.order_id} done')
        ->expectations(['max_retries' => 0]));

    expect(fn () => app(GraphExecutor::class)->execute($graph, new State([])))->toThrow(RuntimeException::class);

    expect($exporter->sent[0]['trace']['status'])->toBe('failed')
        ->and($exporter->sent[0]['trace']['annotations'])->toBe(['graph' => ['label' => 'Failing'], 'outcome' => null, 'expectations' => ['max_retries' => 0]])
        ->and(anSpan($exporter->sent[0], 'boom'))->not->toHaveKey('annotations');
});

it('a run that pauses and then fails merges to a null outcome, not the partial one', function () {
    $exporter = anExporter();
    $graph = anGraph('an-pause-fail', [['lookup', AnLookupNode::class], ['approval', AnApprovalNode::class], ['boom', AnBoomNode::class]], fn (GraphBuilder $b) => $b
        ->outcome('Order {order_lookup.order_id}'));
    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    $paused = $exporter->sent[0];
    $exporter->reset();
    anForgetServices();

    expect(fn () => app(GraphExecutor::class)->resumeWithInput($state->getInterruptCheckpointId(), ['decision' => 'yes', 'note' => 'ok']))->toThrow(RuntimeException::class);
    $failed = end($exporter->sent);
    // A receiver decoding JSON to arrays, as the reference merger does.
    $roundTrip = fn (array $envelope): array => json_decode(json_encode($envelope, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($paused['trace']['annotations']['outcome']['partial'])->toBeTrue()
        ->and($failed['trace']['annotations'])->toBe(['outcome' => null])
        ->and(TraceSegmentMergerTestAccess::merge([$roundTrip($paused), $roundTrip($failed)])['trace']['annotations'])->toBe(['outcome' => null]);
});

it('a waiting run sends a partial outcome; after the resume the merged trace has the final one and the declared human input', function () {
    $exporter = anExporter();
    $graph = anGraph('an-refund', [['lookup', AnLookupNode::class], ['approval', AnApprovalNode::class], ['answer', AnPlainNode::class]], fn (GraphBuilder $b) => $b
        ->outcome('Order {order_lookup.order_id} is {order_lookup.status}', ['approved']));

    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    $paused = $exporter->sent[0];

    expect($paused['trace']['status'])->toBe('interrupted')
        ->and($paused['trace']['annotations']['outcome'])->toBe([
            'headline' => 'Order 3 is processing',
            'fields' => [['path' => 'approved', 'value' => null]],
            'partial' => true,
            'missing' => ['approved'],
        ])
        ->and(TraceSegmentMergerTestAccess::merge([$paused])['trace']['annotations']['outcome']['partial'])->toBeTrue();

    $exporter->reset();
    anForgetServices();
    app(GraphExecutor::class)->resumeWithInput($state->getInterruptCheckpointId(), ['decision' => 'no', 'note' => 'Needs another revision pass.']);
    $final = end($exporter->sent);

    $resume = array_values(array_filter($final['spans'], fn (array $s): bool => $s['span_type'] === 'resume'))[0];
    $merged = TraceSegmentMergerTestAccess::merge([$paused, $final]);

    expect($resume['human_input'])->toBe(['approved' => false, 'approval_comments' => 'Needs another revision pass.'])
        ->and($merged['trace']['annotations']['outcome'])->toBe([
            'headline' => 'Order 3 is kept',
            'fields' => [['path' => 'approved', 'value' => false]],
            'partial' => false,
            'missing' => [],
        ])
        ->and(anJson($exporter->sent))->not->toContain('boss@example.com', 'decision');
});

class TraceSegmentMergerTestAccess
{
    /**
     * @param  array<int, array<string, mixed>>  $envelopes
     * @return array<string, mixed>
     */
    public static function merge(array $envelopes): array
    {
        return (new TraceSegmentMerger)->merge($envelopes);
    }
}

it('the stream resume path sends the same human input', function () {
    $exporter = anExporter();
    $graph = anGraph('an-stream-refund', [['approval', AnApprovalNode::class], ['answer', AnPlainNode::class]]);
    $state = app(GraphExecutor::class)->execute($graph, new State([]));

    $exporter->reset();
    anForgetServices();
    app(StreamResumption::class)
        ->resumeStream($state->getInterruptCheckpointId(), ['decision' => 'yes', 'note' => 'ok'], new StreamOptions(delivery: 'iterator'))
        ->getFinalState();

    $resume = array_values(array_filter(end($exporter->sent)['spans'], fn (array $s): bool => $s['span_type'] === 'resume'))[0];
    expect($resume['human_input'])->toBe(['approved' => true, 'approval_comments' => 'ok']);
});

it('sends tool expectations on that tool\'s spans only, wrapped and unwrapped', function () {
    $exporter = anExporter();
    app(GraphExecutor::class)->execute(anGraph('an-tools', [['t', AnToolNode::class]], fn (GraphBuilder $b) => $b
        ->toolExpectations('lookup_order', ['read_only' => true])), new State([]));

    $tools = array_values(array_filter($exporter->sent[0]['spans'], fn (array $s): bool => $s['span_type'] === 'tool'));
    $byName = fn (string $name): array => array_values(array_filter($tools, fn (array $s): bool => $s['node_name'] === $name));

    expect($byName('lookup_order'))->toHaveCount(2)
        ->and(array_column($byName('lookup_order'), 'annotations'))->toBe([['expectations' => ['read_only' => true]], ['expectations' => ['read_only' => true]]])
        ->and($byName('send_email')[0])->not->toHaveKey('annotations');
});

it('expectations never stop or change a run', function () {
    AnFlakyNode::$calls = 0;
    $exporter = anExporter();
    $router = new class implements RouterInterface
    {
        public function route(StateContract $state): string|array
        {
            return $state->get('loops') < 3 ? 'flaky' : 'end';
        }

        public function getAvailableRoutes(): array
        {
            return ['flaky', 'end'];
        }

        public function getDefaultRoute(): ?string
        {
            return null;
        }

        public function validateRoute(string $route): bool
        {
            return true;
        }
    };
    $graph = GraphBuilder::create('an-loose')
        ->addNode('flaky', AnFlakyNode::class)->addNode('end', AnPlainNode::class)
        ->addConditionalEdge('flaky', $router)
        ->setEntryPoint('flaky')->addExitPoint('end')
        ->expectations(['max_retries' => 0, 'max_loops' => 0, 'max_cost_usd' => 0.0, 'read_only' => true])
        ->build();

    $state = app(GraphExecutor::class)->execute($graph, new State([]));

    expect($state->get('loops'))->toBe(3)
        ->and(AnFlakyNode::$calls)->toBe(4)
        ->and($exporter->sent[0]['trace']['status'])->toBe('completed')
        ->and($exporter->sent[0]['trace']['annotations']['expectations'])->toBe(['max_retries' => 0, 'max_loops' => 0, 'max_cost_usd' => 0.0, 'read_only' => true]);
});

it('never calls a model for annotations', function () {
    $fake = Llm::fake();
    anExporter();
    app(GraphExecutor::class)->execute(anOrderGraph('an-no-model'), new State([]));

    $fake->assertNothingSent();
});

it('a queued node job sends its node annotations', function () {
    $exporter = anExporter();
    anOrderGraph('an-job');

    (new ExecuteNodeJob('exec-an-job', 'answer', ['answer' => 'queued', 'items' => [['name' => 'Cup']]], 'an-job'))
        ->handle(app(GraphRegistry::class), app(NodeMiddlewarePipeline::class));

    expect(anSpan($exporter->sent[0], 'answer')['annotations']['description'])->toBe('Sent done for Cup {ok}')
        ->and(app()->bound(Graph::class))->toBeFalse();
});

// The owner switch

it('static sends labels, descriptions and headlines without placeholders, the graph block and expectations only', function () {
    $exporter = anExporter(['annotations' => 'static']);
    $graph = anGraph('an-static', [['lookup', AnLookupNode::class], ['approval', AnApprovalNode::class], ['answer', AnPlainNode::class, ['label' => 'Answer', 'description' => 'Answered {answer}']]], fn (GraphBuilder $b) => $b
        ->label('Order status')
        ->outcome('Order {order_lookup.order_id}', ['order_lookup.total'])
        ->expectations(['read_only' => true])
        ->toolExpectations('lookup_order', ['read_only' => true]));
    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    $exporter->reset();
    anForgetServices();
    app(GraphExecutor::class)->resumeWithInput($state->getInterruptCheckpointId(), ['decision' => 'yes', 'note' => 'NOTE-XYZ']);
    $final = end($exporter->sent);

    expect($final['trace']['annotations'])->toBe(['graph' => ['label' => 'Order status'], 'expectations' => ['read_only' => true]])
        ->and(anSpan($final, 'answer')['annotations'])->toBe(['label' => 'Answer', 'missing' => []])
        ->and(anJson($exporter->sent))->not->toContain('"human_input"', 'Answered', 'NOTE-XYZ');
});

it('static keeps a headline without placeholders and drops the fields', function () {
    $exporter = anExporter(['annotations' => 'static']);
    app(GraphExecutor::class)->execute(anGraph('an-static-headline', [['lookup', AnLookupNode::class]], fn (GraphBuilder $b) => $b
        ->outcome('Order answered {{done}}', ['order_lookup.total'])), new State([]));

    expect($exporter->sent[0]['trace']['annotations'])->toBe(['outcome' => ['headline' => 'Order answered {done}', 'partial' => false, 'missing' => []]])
        ->and(anSpan($exporter->sent[0], 'lookup')['annotations'])->toBe(['label' => 'Look up the order', 'missing' => []]);
});

it('none sends exactly what an unannotated graph sends', function () {
    $run = function (bool $annotated): array {
        $exporter = anExporter(['annotations' => $annotated ? 'none' : 'all']);
        $declare = fn (GraphBuilder $b) => $annotated
            ? $b->label('L')->description('D')->outcome('O {answer}', ['answer'])->expectations(['read_only' => true])->toolExpectations('lookup_order', ['read_only' => true])
            : $b->description('D');
        $graph = anGraph('an-none', [['a', AnPlainNode::class, $annotated ? ['label' => 'A {answer}', 'description' => 'B', 'humanInput' => ['answer']] : []], ['t', AnToolNode::class]], $declare);
        app(GraphExecutor::class)->execute($graph, new State([]));

        return anNormalize($exporter->sent);
    };

    expect(anJson($run(true)))->toBe(anJson($run(false)))
        ->and(anJson($run(true)))->not->toContain('annotations');
});

it('an unknown switch value is treated as none and logs one warning naming it', function () {
    Log::spy();
    $exporter = anExporter(['annotations' => 'everything']);
    $graph = anGraph('an-unknown-switch', [['lookup', AnLookupNode::class], ['t', AnToolNode::class]], fn (GraphBuilder $b) => $b
        ->label('Order status')
        ->outcome('Order {order_lookup.order_id}', ['order_lookup.total'])
        ->expectations(['read_only' => true])
        ->toolExpectations('lookup_order', ['read_only' => true]));
    app(GraphExecutor::class)->execute($graph, new State([]));
    app(GraphExecutor::class)->execute($graph, new State([]));

    expect(anJson($exporter->sent))->not->toContain('annotations', 'Order status', 'Look up the order');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'agenticgraph.annotations.unknown_switch'
        && $context['value'] === 'everything');
});

it('OTLP gets span annotations and human_input as attributes, and none of them with the switch at none', function (string $switch) {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'otlp',
        'agenticgraph.tracing.otlp.endpoint' => 'https://otlp.example.com/v1/traces',
        'agenticgraph.tracing.capture.annotations' => $switch,
        'agenticgraph.checkpoint.enabled' => true,
    ]);
    app()->forgetInstance('request');
    Http::fake();
    app()->instance(TracerExporter::class, new OtlpExporter);
    anForgetServices();

    $graph = anGraph('an-otlp-'.$switch, [['lookup', AnLookupNode::class], ['approval', AnApprovalNode::class], ['t', AnToolNode::class]], fn (GraphBuilder $b) => $b
        ->label('Order status')->outcome('Order {order_lookup.order_id}')->toolExpectations('lookup_order', ['read_only' => true]));
    $state = app(GraphExecutor::class)->execute($graph, new State([]));
    anForgetServices();
    app(GraphExecutor::class)->resumeWithInput($state->getInterruptCheckpointId(), ['decision' => 'yes', 'note' => 'ok']);

    $keys = [];
    foreach (Http::recorded() as [$request]) {
        foreach ($request->data()['resourceSpans'] as $resourceSpans) {
            foreach ($resourceSpans['scopeSpans'][0]['spans'] as $span) {
                $keys = [...$keys, ...array_column($span['attributes'], 'key')];
            }
        }
    }
    $annotationKeys = array_values(array_unique(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'annotations.') || str_starts_with($key, 'human_input.'))));
    sort($annotationKeys);

    expect(Http::recorded())->not->toBeEmpty()
        ->and($annotationKeys)->toBe($switch === 'none' ? [] : [
            'annotations.description', 'annotations.expectations.read_only', 'annotations.label', 'annotations.missing', 'human_input.approval_comments', 'human_input.approved',
        ])
        ->and(json_encode(array_map(fn (array $r) => $r[0]->data(), Http::recorded()->all())))->not->toContain('Order 3');
})->with(['all', 'none']);
