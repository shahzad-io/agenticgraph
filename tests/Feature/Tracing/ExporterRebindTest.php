<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Shahzad\AgenticGraph\AgenticGraphServiceProvider;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\ExecutionClock;
use Shahzad\AgenticGraph\Tracing\Instrumentation\DatabaseInstrumentation;
use Shahzad\AgenticGraph\Tracing\Instrumentation\HttpClientInstrumentation;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;
use Shahzad\AgenticGraph\Tracing\Tracer;

class RebindCapturingExporter extends PulseExporter
{
    /** @var array<int, array<string, mixed>> */
    public array $spans = [];

    public function isEnabled(): bool
    {
        return true;
    }

    public function addSpan(array $span): void
    {
        $this->spans[] = $span;
    }
}

/**
 * The instrumentation singletons are resolved at boot (Event::subscribe and
 * DatabaseInstrumentation::register). Rebinding TracerExporter afterwards must still
 * route their spans to the new exporter.
 */
it('sends http, db and tracer spans to a TracerExporter rebound at runtime', function () {
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.capture.http' => true,
        'agenticgraph.tracing.capture.db' => true,
    ]);
    Http::fake(['rebind.test/*' => Http::response(['ok' => true])]);

    // Boot as a tracing-enabled app does: the singletons are resolved with the boot exporter.
    $bootExporter = new RebindCapturingExporter;
    app()->instance(TracerExporter::class, $bootExporter);
    $provider = app()->getProvider(AgenticGraphServiceProvider::class);
    (fn () => $this->registerTracing())->call($provider);
    $tracer = app(Tracer::class);
    expect(app()->resolved(HttpClientInstrumentation::class))->toBeTrue()
        ->and(app()->resolved(DatabaseInstrumentation::class))->toBeTrue();

    $exporter = new RebindCapturingExporter;
    app()->instance(TracerExporter::class, $exporter);

    $context = new TraceContext('exec_rebind', 'span_root');
    $context->startSpan('probe-tool', 'tool');
    app()->instance(TraceContext::class, $context);
    app()->instance(ExecutionClock::class, new ExecutionClock('exec_rebind'));

    Http::get('https://rebind.test/ping');
    DB::select('select 1');
    $tracer->span('custom-step', fn () => null);

    $types = array_column($exporter->spans, 'span_type');

    expect($types)->toContain('http')
        ->and($types)->toContain('db')
        ->and($types)->toContain('custom')
        ->and($bootExporter->spans)->toBe([])
        ->and(app(HttpClientInstrumentation::class))->toBe(app(HttpClientInstrumentation::class))
        ->and(app(DatabaseInstrumentation::class))->toBe(app(DatabaseInstrumentation::class));
});
