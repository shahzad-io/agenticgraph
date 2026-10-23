<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Events\TokensUsed;
use Shahzad\AgenticGraph\Execution\ExecutionContextManager;
use Shahzad\AgenticGraph\Execution\ExecutionCoordinator;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Execution\InterruptManager;
use Shahzad\AgenticGraph\Execution\ParallelExecutionCoordinator;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\FileExporter;
use Shahzad\AgenticGraph\Tracing\Exporters\LogExporter;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TracerExporterFactory;

/**
 * Sends every lifecycle call to two exporters, so both see the same run.
 */
class TeeTraceExporter implements TracerExporter
{
    public function __construct(private TracerExporter $a, private TracerExporter $b) {}

    public function isEnabled(): bool
    {
        return $this->a->isEnabled() && $this->b->isEnabled();
    }

    public function isCapturing(): bool
    {
        return $this->a->isCapturing() || $this->b->isCapturing();
    }

    public function getTraceStartTime(string $traceId): ?Carbon
    {
        return $this->a->getTraceStartTime($traceId);
    }

    public function startTrace(string $traceId, string $graphName, array $initialStateKeys = [], ?DateTimeImmutable $startedAt = null, ?array $initialStateData = null): void
    {
        $this->a->startTrace($traceId, $graphName, $initialStateKeys, $startedAt, $initialStateData);
        $this->b->startTrace($traceId, $graphName, $initialStateKeys, $startedAt, $initialStateData);
    }

    public function addSpan(array $span): void
    {
        $this->a->addSpan($span);
        $this->b->addSpan($span);
    }

    public function addLlmCall(array $call): void
    {
        $this->a->addLlmCall($call);
        $this->b->addLlmCall($call);
    }

    public function completeTrace(string $traceId, array $finalStateKeys = [], float $durationMs = 0, ?array $finalStateData = null, ?DateTimeInterface $at = null): void
    {
        $this->a->completeTrace($traceId, $finalStateKeys, $durationMs, $finalStateData, $at);
        $this->b->completeTrace($traceId, $finalStateKeys, $durationMs, $finalStateData, $at);
    }

    public function failTrace(string $traceId, Throwable $exception, float $durationMs = 0, ?array $finalStateData = null, ?DateTimeInterface $at = null): void
    {
        $this->a->failTrace($traceId, $exception, $durationMs, $finalStateData, $at);
        $this->b->failTrace($traceId, $exception, $durationMs, $finalStateData, $at);
    }

    public function interruptTrace(string $traceId, string $reason, ?DateTimeInterface $at = null, ?DateTimeInterface $expiresAt = null): void
    {
        $this->a->interruptTrace($traceId, $reason, $at, $expiresAt);
        $this->b->interruptTrace($traceId, $reason, $at, $expiresAt);
    }

    public function resumeTrace(string $traceId, ?string $graphName = null, string $kind = 'resumed', ?DateTimeInterface $at = null): void
    {
        $this->a->resumeTrace($traceId, $graphName, $kind, $at);
        $this->b->resumeTrace($traceId, $graphName, $kind, $at);
    }

    public function flush(string $traceId): void
    {
        $this->a->flush($traceId);
        $this->b->flush($traceId);
    }
}

class TeLlmNode extends AbstractNode
{
    public static bool $fail = false;

    public function getRetryAttempts(): int
    {
        return 0;
    }

    public function execute(StateContract $state): StateContract
    {
        $start = microtime(true);
        usleep(1000);
        event(TokensUsed::fromLlmCall('llm', $start, (microtime(true) - $start) * 1000, 3, 5, 'text', 'test:model', 'prompt', 'response'));

        if (self::$fail) {
            throw new RuntimeException('node failed');
        }

        return $state->set('answer', 'done');
    }
}

function teDir(): string
{
    return sys_get_temp_dir().'/agenticgraph-trace-test-'.getmypid();
}

function teEnableTracing(): void
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.api_key' => 'test-key',
        'agenticgraph.tracing.endpoint' => 'https://ingest.test/api',
        'agenticgraph.tracing.capture.prompts' => true,
        'agenticgraph.tracing.capture.responses' => true,
    ]);
    // Console context: every exporter sends during flush.
    app()->forgetInstance('request');
}

/**
 * @return array<int, array<string, mixed>>
 */
function teFiles(string $traceId): array
{
    $files = glob(teDir().'/'.$traceId.'/*.json') ?: [];
    sort($files);

    return array_map(fn (string $f): array => json_decode((string) file_get_contents($f), true, flags: JSON_THROW_ON_ERROR), $files);
}

/**
 * @return array<int, array<string, mixed>>
 */
function tePulseBodies(): array
{
    return Http::recorded()->map(fn (array $pair): array => json_decode($pair[0]->body(), true))->values()->all();
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function teWithoutClock(array $payload): array
{
    // Each exporter mints its own segment id; everything else must match.
    unset($payload['_meta']['truncated_at'], $payload['segment']['id']);

    return $payload;
}

function teTee(): TeeTraceExporter
{
    $tee = new TeeTraceExporter(new PulseExporter, new FileExporter(teDir()));
    app()->instance(TracerExporter::class, $tee);
    foreach ([ExecutionContextManager::class, InterruptManager::class, ParallelExecutionCoordinator::class, ExecutionCoordinator::class, GraphExecutor::class] as $service) {
        app()->forgetInstance($service);
    }

    return $tee;
}

beforeEach(function () {
    File::deleteDirectory(teDir());
    Http::fake(['*' => Http::response(['status' => 'captured'], 202)]);
    TeLlmNode::$fail = false;
    teEnableTracing();
});

afterEach(function () {
    File::deleteDirectory(teDir());
    Cache::flush();
});

it('pulse and file carry the same envelope for a real run', function (bool $fail) {
    teTee();
    TeLlmNode::$fail = $fail;
    $graph = GraphBuilder::create('te-run')->addNode('llm', TeLlmNode::class)->setEntryPoint('llm')->addExitPoint('llm')->build();

    try {
        app(GraphExecutor::class)->execute($graph, new State(['q' => 'x']));
    } catch (RuntimeException) {
    }

    $pulse = tePulseBodies();
    expect($pulse)->toHaveCount(1);
    $files = teFiles($pulse[0]['trace']['id']);

    expect($files)->toHaveCount(1)
        ->and(teWithoutClock($files[0]))->toBe(teWithoutClock($pulse[0]))
        ->and($pulse[0]['schema_version'])->toBe('agenticgraph.trace.v1.1')
        ->and($pulse[0]['trace']['status'])->toBe($fail ? 'failed' : 'completed')
        ->and($pulse[0]['llm_calls'])->toHaveCount(1)
        ->and(collect($pulse[0]['spans'])->pluck('span_type')->sort()->values()->all())->toBe(['graph', 'llm', 'node']);
})->with(['completed' => false, 'failed' => true]);

it('pulse and file truncate an oversize payload the same way', function () {
    $tee = teTee();
    $tee->startTrace('tr_big', 'te-big', ['q'], new DateTimeImmutable('2026-09-27T10:00:00+00:00'));
    $tee->addLlmCall(['id' => 'c1', 'trace_id' => 'tr_big', 'response' => str_repeat('word ', 300_000)]);
    $tee->completeTrace('tr_big', [], 1500.0);

    $pulse = tePulseBodies();
    $files = teFiles('tr_big');

    expect($pulse[0]['_meta']['truncation_applied'])->toBe(['responses'])
        ->and(teWithoutClock($files[0]))->toBe(teWithoutClock($pulse[0]));
});

it('file exporter writes one file per segment, interrupted then completed across processes', function () {
    config(['agenticgraph.tracing.exporter' => 'file', 'agenticgraph.tracing.file.path' => teDir()]);

    $first = new FileExporter;
    $first->startTrace('tr_resume', 'refund', ['email']);
    $first->addSpan(['id' => 'span_before', 'trace_id' => 'tr_resume', 'span_type' => 'node', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'duration_ms' => 5]);
    $first->interruptTrace('tr_resume', 'approval');

    // A new instance stands in for the resume worker; it sends only its own spans.
    $second = new FileExporter;
    $second->resumeTrace('tr_resume');
    $second->addSpan(['id' => 'span_after', 'trace_id' => 'tr_resume', 'span_type' => 'resume', 'started_at' => '2026-09-27T10:05:00.000000+00:00', 'duration_ms' => 5]);
    $second->completeTrace('tr_resume', ['decision'], 0);

    $names = array_map('basename', glob(teDir().'/tr_resume/*.json') ?: []);
    $files = teFiles('tr_resume');

    expect($names)->toHaveCount(2)
        ->and($names[0])->toMatch('/^\d{8}-\d{6}-\d{6}\.json$/')
        ->and($files[0]['trace']['status'])->toBe('interrupted')
        ->and(array_column($files[0]['spans'], 'id'))->toBe(['span_before'])
        ->and($files[1]['trace']['status'])->toBe('completed')
        ->and($files[1]['trace'])->toHaveKey('resumed_at')
        ->and($files[1]['segment']['kind'])->toBe('resumed')
        ->and(array_column($files[1]['spans'], 'id'))->toBe(['span_after']);
});

it('pulse and file keep the zero fraction of float fields on the wire', function () {
    $tee = teTee();
    $tee->startTrace('exec_te_zero', 'g');
    $tee->addSpan(['id' => 'span_zero', 'trace_id' => 'exec_te_zero', 'span_type' => 'llm', 'cost_usd' => 0.0, 'duration_ms' => 12.0]);
    $tee->completeTrace('exec_te_zero', [], 10.0);

    $file = (string) file_get_contents((glob(teDir().'/exec_te_zero/*.json') ?: [''])[0]);
    $body = Http::recorded()->first()[0]->body();

    expect($file)->toContain('"cost_usd": 0.0')->toContain('"duration_ms": 12.0')
        ->and($body)->toContain('"cost_usd":0.0')->toContain('"duration_ms":12.0');
});

it('file exporter keeps hostile trace ids inside the configured path', function () {
    $exporter = new FileExporter(teDir());
    $exporter->startTrace('../../escape', 'g');
    $exporter->completeTrace('../../escape', [], 10.0);

    $written = glob(teDir().'/*/*.json') ?: [];

    expect($written)->toHaveCount(1)
        ->and(dirname($written[0], 2))->toBe(teDir())
        ->and(file_exists(dirname(teDir()).'/escape'))->toBeFalse();
});

it('factory resolves the file driver and names it in the error message', function () {
    config(['agenticgraph.tracing.exporter' => 'file']);

    expect(TracerExporterFactory::make(app()))->toBeInstanceOf(FileExporter::class);

    config(['agenticgraph.tracing.exporter' => 'nope']);
    expect(fn () => TracerExporterFactory::make(app()))
        ->toThrow(InvalidArgumentException::class, 'Supported: auto, pulse, log, file, null, otlp.');
});

it('log exporter logs the shared envelope', function () {
    Log::spy();
    $exporter = new LogExporter;

    $exporter->startTrace('tr_log', 'g', ['q'], new DateTimeImmutable('2026-09-27T10:00:00+00:00'));
    $exporter->addSpan(['id' => 's1', 'trace_id' => 'tr_log', 'status' => 'interrupted', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'duration_ms' => 1]);
    $exporter->addSpan(['id' => 's1', 'trace_id' => 'tr_log', 'status' => 'completed', 'started_at' => '2026-09-27T10:00:00.000000+00:00', 'duration_ms' => 1]);
    $exporter->completeTrace('tr_log', [], 250.0);

    Log::shouldHaveReceived('info')->once()->with('agenticgraph.trace', Mockery::on(function (array $payload): bool {
        return array_key_first($payload) === 'schema_version'
            && $payload['schema_version'] === 'agenticgraph.trace.v1.1'
            && $payload['trace']['sampled'] === true
            && $payload['trace']['resource']['service.name'] !== ''
            && array_key_exists('deployment.environment.name', $payload['trace']['resource'])
            && ! array_key_exists('deployment.environment', $payload['trace']['resource'])
            && $payload['trace']['instrumentation_scope'] !== ''
            && $payload['trace']['active_ms'] === 250.0
            && ! array_key_exists('exporter', $payload['trace'])
            && count($payload['spans']) === 1
            && $payload['spans'][0]['status'] === 'completed';
    }));
});

it('log exporter logs the resumed segment', function () {
    Log::spy();
    $exporter = new LogExporter;

    $exporter->startTrace('tr_log_resume', 'g');
    $exporter->interruptTrace('tr_log_resume', 'approval');
    $exporter->resumeTrace('tr_log_resume');
    $exporter->addSpan(['id' => 'after', 'trace_id' => 'tr_log_resume', 'span_type' => 'resume']);
    $exporter->completeTrace('tr_log_resume', [], 0);

    Log::shouldHaveReceived('info')->with('agenticgraph.trace', Mockery::on(
        fn (array $payload): bool => $payload['trace']['status'] === 'interrupted'
    ))->once();
    Log::shouldHaveReceived('info')->with('agenticgraph.trace', Mockery::on(
        fn (array $payload): bool => $payload['trace']['status'] === 'completed'
            && isset($payload['trace']['resumed_at'])
            && array_column($payload['spans'], 'id') === ['after']
    ))->once();
});

it('resolves tracing defaults from the package config when the app has not published it', function () {
    expect(config('agenticgraph.tracing.exporter'))->toBe('auto')
        ->and(config('agenticgraph.tracing.file.path'))->toBe(storage_path('app/agenticgraph/traces'));
});
