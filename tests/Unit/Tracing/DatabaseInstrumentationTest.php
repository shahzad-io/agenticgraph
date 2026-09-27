<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;
use Shahzad\AgenticGraph\Tracing\DbQueryType;
use Shahzad\AgenticGraph\Tracing\Instrumentation\DatabaseInstrumentation;
use Shahzad\AgenticGraph\Tracing\PulseExporter;
use Shahzad\AgenticGraph\Tracing\TraceContext;

function enableDbInstrumentationTracingExporter(): PulseExporter
{
    config([
        'agenticgraph.tracing.enabled' => true,
        'agenticgraph.tracing.exporter' => 'pulse',
        'agenticgraph.tracing.api_key' => 'pk_test_db_instrumentation',
        'agenticgraph.tracing.endpoint' => 'https://api.example.com',
    ]);

    return new PulseExporter;
}

function registerDbInstrumentation(TracerExporter $exporter): DatabaseInstrumentation
{
    $instrumentation = new DatabaseInstrumentation($exporter, 1000.0);
    $instrumentation->register(app('events'));

    return $instrumentation;
}

function bindDbInstrumentationTraceContext(string $executionId = 'exec-db-instrumentation'): TraceContext
{
    $traceContext = new TraceContext($executionId, 'span_root');
    app()->instance(TraceContext::class, $traceContext);

    return $traceContext;
}

it('classifies SELECT and related verbs as read', function (string $sql): void {
    expect(DbQueryType::fromSql($sql))->toBe('read');
})->with([
    'select' => ['select * from `demo_orders` where `id` = ? limit 1'],
    'with cte' => ['WITH recent AS (SELECT id FROM orders) SELECT * FROM recent'],
    'show' => ['SHOW TABLES'],
    'explain' => ['EXPLAIN SELECT * FROM demo_orders'],
    'describe' => ['DESCRIBE demo_orders'],
]);

it('classifies mutating and DDL verbs as write', function (string $sql): void {
    expect(DbQueryType::fromSql($sql))->toBe('write');
})->with([
    'insert' => ['insert into `demo_orders` (`status`) values (?)'],
    'update' => ['update `demo_orders` set `status` = ? where `id` = ?'],
    'delete' => ['delete from `demo_orders` where `id` = ?'],
    'replace' => ['REPLACE INTO demo_orders (id, status) VALUES (?, ?)'],
    'create' => ['CREATE TABLE tracing_probe (id INTEGER PRIMARY KEY)'],
    'alter' => ['ALTER TABLE demo_orders ADD COLUMN probe TEXT'],
    'drop' => ['DROP TABLE tracing_probe'],
    'truncate' => ['TRUNCATE TABLE demo_orders'],
]);

it('returns null for unrecognized sql', function (): void {
    expect(DbQueryType::fromSql('CALL refresh_materialized_views()'))->toBeNull();
});

it('maps SELECT db spans to read even when the connection readWriteType is write', function (): void {
    $exporter = enableDbInstrumentationTracingExporter();
    registerDbInstrumentation($exporter);
    bindDbInstrumentationTraceContext('exec-db-read-type');

    config(['agenticgraph.tracing.capture.db' => true]);

    $instrumentation = new DatabaseInstrumentation($exporter, 1000.0);
    $connection = DB::connection('testing');

    $event = new QueryExecuted(
        'select * from `users` where `id` = ?',
        [1],
        1.2,
        $connection,
        'write',
    );

    $instrumentation->handleQuery($event);

    $span = $exporter->getBuffer()['spans'][0];

    expect($span['attributes'])->toBe([
        'laravel.db.connection' => 'testing',
        'laravel.db.statement_type' => 'read',
        'db.operation.name' => 'select',
        'laravel.db.bindings_count' => 1,
        'laravel.db.query_summary' => 'SELECT users',
    ])
        ->and(array_filter(array_keys($span), fn (string $key): bool => str_starts_with($key, 'db.')))->toBe([]);
});

it('puts full sql in db.query.text and leaves db.operation.name out of multi-statement text', function (): void {
    $exporter = enableDbInstrumentationTracingExporter();
    registerDbInstrumentation($exporter);
    bindDbInstrumentationTraceContext('exec-db-full-sql');

    config(['agenticgraph.tracing.capture.db' => true, 'agenticgraph.tracing.capture.db_sql' => 'full']);

    $instrumentation = new DatabaseInstrumentation($exporter, 1000.0);
    $connection = DB::connection('testing');
    $instrumentation->handleQuery(new QueryExecuted('UPDATE `users` SET `name` = ?', ['x'], 1.0, $connection));
    $instrumentation->handleQuery(new QueryExecuted('DELETE FROM a; DELETE FROM b', [], 1.0, $connection));

    [$single, $multi] = $exporter->getBuffer()['spans'];

    expect($single['attributes']['db.query.text'])->toBe('UPDATE `users` SET `name` = ?')
        ->and($single['attributes']['db.operation.name'])->toBe('UPDATE')
        ->and($single['attributes'])->not->toHaveKey('laravel.db.query_summary')
        ->and($multi['attributes'])->not->toHaveKey('db.operation.name')
        ->and($multi['attributes']['laravel.db.statement_type'])->toBe('write');
});

it('captures read and write db span types from real queries inside a tool span', function (): void {
    if (! Schema::hasTable('tracing_db_probe')) {
        Schema::create('tracing_db_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->timestamps();
        });
    }

    DB::table('tracing_db_probe')->truncate();

    $exporter = enableDbInstrumentationTracingExporter();
    registerDbInstrumentation($exporter);

    $context = new TraceContext('exec-db-live', 'span_root');
    $context->startSpan('probe-tool', 'tool');
    app()->instance(TraceContext::class, $context);

    config(['agenticgraph.tracing.capture.db' => false]);

    DB::table('tracing_db_probe')->insert(['label' => 'probe-read', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('tracing_db_probe')->where('label', 'probe-read')->get();

    $dbSpans = array_values(array_filter(
        $exporter->getBuffer()['spans'],
        fn (array $span): bool => ($span['span_type'] ?? '') === 'db',
    ));

    $readSpans = array_values(array_filter($dbSpans, fn (array $span): bool => ($span['attributes']['laravel.db.statement_type'] ?? null) === 'read'));
    $writeSpans = array_values(array_filter($dbSpans, fn (array $span): bool => ($span['attributes']['laravel.db.statement_type'] ?? null) === 'write'));

    expect($readSpans)->not->toBeEmpty()
        ->and($writeSpans)->not->toBeEmpty()
        ->and(collect($dbSpans)->every(fn (array $span): bool => ! array_key_exists('db.rows_affected', $span) && ! array_key_exists('db.type', $span)))->toBeTrue();

    Schema::dropIfExists('tracing_db_probe');
});

it('writes db spans with microsecond timestamps that match the query time', function (): void {
    $exporter = enableDbInstrumentationTracingExporter();
    bindDbInstrumentationTraceContext('exec-db-precision');
    config(['agenticgraph.tracing.capture.db' => true]);

    (new DatabaseInstrumentation($exporter, 1000.0))->handleQuery(
        new QueryExecuted('select 1', [], 1.234, DB::connection('testing'))
    );

    $span = $exporter->getBuffer()['spans'][0];
    $micros = fn (string $at): int => (int) (new DateTimeImmutable($at))->format('Uu');

    expect($span['started_at'])->toMatch(TRACE_TIME_PATTERN)
        ->and($span['ended_at'])->toMatch(TRACE_TIME_PATTERN)
        ->and($micros($span['ended_at']) - $micros($span['started_at']))->toBe(1234);
});
