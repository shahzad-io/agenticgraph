<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Tool;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\ToolCallCaptured;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\Instrumentation\DatabaseInstrumentation;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;
use Shahzad\AgenticGraph\Tracing\InstrumentationGate;
use Shahzad\AgenticGraph\Tracing\Listeners\TracingListener;
use Shahzad\AgenticGraph\Tracing\TraceContext;

/**
 * @extends PrismNode
 */
final class ToolWrapProbeNode extends PrismNode
{
    protected ?string $name = 'convert';

    public function execute(StateContract $state): StateContract
    {
        $tool = (new Tool)->as('fetch_rate')
            ->for('Fetch an exchange rate')
            ->using(fn (): string => (string) Http::get('https://api.exchangerate.test/latest')->body());

        $wrapped = $this->wrapToolsForTracing([$tool], 'prism');
        $result = $wrapped[0]->handle();

        return $state->set('result', $result);
    }
}

final class CapturingToolTracerExporter implements TracerExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $spans = [];

    public bool $forcedEnabled = true;

    public function isEnabled(): bool
    {
        return $this->forcedEnabled;
    }

    public function isCapturing(): bool
    {
        return false;
    }

    public function getTraceStartTime(string $traceId): ?Carbon
    {
        return null;
    }

    public function startTrace(
        string $traceId,
        string $graphName,
        array $initialStateKeys = [],
        ?DateTimeImmutable $startedAt = null,
        ?array $initialStateData = null
    ): void {}

    public function addSpan(array $span): void
    {
        $this->spans[] = $span;
    }

    public function addLlmCall(array $call): void {}

    public function completeTrace(
        string $traceId,
        array $finalStateKeys = [],
        float $durationMs = 0,
        ?array $finalStateData = null,
        ?DateTimeInterface $at = null
    ): void {}

    public function failTrace(
        string $traceId,
        Throwable $exception,
        float $durationMs = 0,
        ?array $finalStateData = null,
        ?DateTimeInterface $at = null
    ): void {}

    public function interruptTrace(string $traceId, string $reason, ?DateTimeInterface $at = null): void {}

    public function resumeTrace(string $traceId, ?string $graphName = null, string $kind = 'resumed', ?DateTimeInterface $at = null): void {}

    public function flush(string $traceId): void {}
}

function registerHttpInstrumentationForToolTests(CapturingToolTracerExporter $exporter): HttpClientInstrumentation
{
    $instrumentation = new HttpClientInstrumentation($exporter);
    foreach ($instrumentation->subscribe(app('events')) as $event => $handler) {
        app('events')->listen($event, [$instrumentation, $handler]);
    }

    return $instrumentation;
}

function registerDbInstrumentationForToolTests(CapturingToolTracerExporter $exporter): DatabaseInstrumentation
{
    $instrumentation = new DatabaseInstrumentation($exporter, 1000.0);
    $instrumentation->register(app('events'));

    return $instrumentation;
}

beforeEach(function (): void {
    config(['agenticgraph.tracing.enabled' => true]);
    app()->forgetInstance(TraceContext::class);
    app()->forgetInstance(ExecutionClock::class);
    app()->forgetInstance(TracerExporter::class);
});

it('nests tool-internal http under the tool span via the prism tool wrap', function (): void {
    Http::fake(['api.exchangerate.test/*' => Http::response(['rate' => 1.23], 200)]);

    $exporter = new CapturingToolTracerExporter;
    app()->instance(TracerExporter::class, $exporter);
    registerHttpInstrumentationForToolTests($exporter);

    $context = new TraceContext('exec_tool_nest', 'span_root');
    $nodeSpanId = $context->startSpan('conversion-node', 'node');
    app()->instance(TraceContext::class, $context);
    app()->instance(ExecutionClock::class, new ExecutionClock('exec_tool_nest'));

    $node = new ToolWrapProbeNode;
    $node->execute(new State);

    $toolSpan = collect($exporter->spans)->firstWhere('span_type', 'tool');
    $httpSpan = collect($exporter->spans)->firstWhere('span_type', 'http');

    expect($toolSpan)->not->toBeNull()
        ->and($httpSpan)->not->toBeNull()
        ->and($toolSpan['parent_id'])->toBe($nodeSpanId)
        ->and($httpSpan['parent_id'])->toBe($toolSpan['id'])
        ->and($httpSpan['parent_id'])->not->toBe($nodeSpanId);
});

it('does not double-export tool spans from ToolCallCaptured when already exported by the wrap', function (): void {
    Http::fake(['api.exchangerate.test/*' => Http::response(['rate' => 1.0], 200)]);

    $exporter = new CapturingToolTracerExporter;
    app()->instance(TracerExporter::class, $exporter);
    registerHttpInstrumentationForToolTests($exporter);

    $listener = new TracingListener($exporter);
    $capturedEvents = 0;

    app('events')->listen(ToolCallCaptured::class, function (ToolCallCaptured $event) use (&$capturedEvents): void {
        $capturedEvents++;
        expect($event->spanAlreadyExported)->toBeTrue();
        $listener->onToolCallCaptured($event);
    });

    $context = new TraceContext('exec_no_double', 'span_root');
    $context->startSpan('conversion-node', 'node');
    app()->instance(TraceContext::class, $context);
    app()->instance(ExecutionClock::class, new ExecutionClock('exec_no_double'));

    (new ToolWrapProbeNode)->execute(new State);

    $toolSpans = array_values(array_filter(
        $exporter->spans,
        fn (array $span): bool => ($span['span_type'] ?? '') === 'tool'
    ));

    expect($capturedEvents)->toBe(1)
        ->and($toolSpans)->toHaveCount(1);
});

it('gates unrelated node db queries by default but captures inside tool spans', function (): void {
    $exporter = new CapturingToolTracerExporter;
    registerDbInstrumentationForToolTests($exporter);

    $context = new TraceContext('exec_db_gate', 'span_root');
    $context->startSpan('bookkeeping-node', 'node');
    app()->instance(TraceContext::class, $context);

    expect(InstrumentationGate::shouldCaptureDb())->toBeFalse();

    config(['agenticgraph.tracing.capture.db' => true]);
    expect(InstrumentationGate::shouldCaptureDb())->toBeTrue();

    config(['agenticgraph.tracing.capture.db' => false]);
    $context->startSpan('lookup_rate', 'tool');
    expect(InstrumentationGate::shouldCaptureDb())->toBeTrue();
});

it('captures broad node http only when capture.http is enabled', function (): void {
    $context = new TraceContext('exec_http_gate', 'span_root');
    $context->startSpan('node-only', 'node');
    app()->instance(TraceContext::class, $context);

    expect(InstrumentationGate::shouldCaptureHttp())->toBeFalse();

    config(['agenticgraph.tracing.capture.http' => true]);
    expect(InstrumentationGate::shouldCaptureHttp())->toBeTrue();
});

it('runs concurrent-marked tools without error under the sync concurrency driver', function (): void {
    Http::fake(['api.exchangerate.test/*' => Http::response(['rate' => 2.0], 200)]);

    $exporter = new CapturingToolTracerExporter;
    app()->instance(TracerExporter::class, $exporter);
    registerHttpInstrumentationForToolTests($exporter);

    $context = new TraceContext('exec_concurrent', 'span_root');
    $context->startSpan('conversion-node', 'node');
    app()->instance(TraceContext::class, $context);
    app()->instance(ExecutionClock::class, new ExecutionClock('exec_concurrent'));

    $tool = (new Tool)->as('parallel_fetch')
        ->for('Parallel fetch')
        ->concurrent(true)
        ->using(fn (): string => (string) Http::get('https://api.exchangerate.test/latest')->body());

    $node = new class extends PrismNode
    {
        protected ?string $name = 'probe';

        public function execute(StateContract $state): StateContract
        {
            return $state;
        }

        /**
         * @param  array<int, Tool>  $tools
         * @return array<int, Tool>
         */
        public function wrapTools(array $tools): array
        {
            return $this->wrapToolsForTracing($tools, 'prism');
        }
    };
    $wrapped = $node->wrapTools([$tool]);
    $result = $wrapped[0]->handle();

    expect($result)->toContain('rate');

    $toolSpan = collect($exporter->spans)->firstWhere('span_type', 'tool');
    expect($toolSpan)->not->toBeNull();
});

it('executes wrapped tools transparently through a real graph executor', function (): void {
    Http::fake(['api.exchangerate.test/*' => Http::response(['rate' => 3.14], 200)]);

    $exporter = new CapturingToolTracerExporter;
    app()->instance(TracerExporter::class, $exporter);
    registerHttpInstrumentationForToolTests($exporter);

    $graph = GraphBuilder::create('tool-tracing-live')
        ->addNode('convert', ToolWrapProbeNode::class)
        ->setEntryPoint('convert')
        ->addExitPoint('convert')
        ->build();

    app(GraphRegistry::class)->register('tool-tracing-live', $graph);

    $handle = app(GraphExecutor::class)->executeWithOptions(
        $graph,
        new State,
        new ExecutionOptions(ExecutionMode::SYNC, StreamingMode::NONE)
    );

    expect($handle->getFinalState()->get('result'))->toContain('rate');

    $toolSpan = collect($exporter->spans)->firstWhere('span_type', 'tool');
    $httpSpan = collect($exporter->spans)->firstWhere('span_type', 'http');

    expect($toolSpan)->not->toBeNull()
        ->and($httpSpan)->not->toBeNull()
        ->and($httpSpan['parent_id'])->toBe($toolSpan['id']);
});
