<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Columns, indexes and foreign keys of the package tables, in a stable order.
 *
 * @return array<string, mixed>
 */
function agenticgraphSchema(): array
{
    $schema = [];

    foreach (['agenticgraph_executions', 'agenticgraph_checkpoints', 'stream_events', 'agenticgraph_interrupts'] as $table) {
        $columns = array_map(fn (array $column): array => array_intersect_key($column, array_flip(['name', 'type', 'nullable', 'default'])), Schema::getColumns($table));
        $indexes = array_map(fn (array $index): array => [$index['columns'], $index['unique']], Schema::getIndexes($table));
        $foreignKeys = array_map(fn (array $key): array => [$key['columns'], $key['foreign_table'], $key['on_delete']], Schema::getForeignKeys($table));
        sort($columns);
        sort($indexes);
        sort($foreignKeys);
        $schema[$table] = compact('columns', 'indexes', 'foreignKeys');
    }

    return $schema;
}

/**
 * The tables TestCase created, so `migrate` starts from an empty app database.
 */
function dropAgenticgraphTables(): void
{
    Schema::disableForeignKeyConstraints();

    foreach (['agenticgraph_checkpoints', 'agenticgraph_executions', 'stream_events', 'agenticgraph_interrupts', 'migrations'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::enableForeignKeyConstraints();
}

/**
 * The provider names published files when it boots (keeping the name of a file the app already has), so each
 * publish step boots a new application, like a new `php artisan` process. ponytail: the files go to the testbench
 * skeleton's migrations folder, shared by concurrent runs of this suite; a temp database path if that ever bites.
 */
function publishAndMigrate(): void
{
    test()->refreshApplication();
    dropAgenticgraphTables();
    Artisan::call('vendor:publish', ['--tag' => 'agenticgraph-migrations']);
    Artisan::call('migrate');
}

function clearPublishedMigrations(): void
{
    File::delete(File::glob(database_path('migrations/*agenticgraph*.php')));
}

beforeEach(fn () => clearPublishedMigrations());

afterEach(fn () => clearPublishedMigrations());

it('brings an app that published the v0.1.0 migrations to the fresh install schema', function () {
    publishAndMigrate();
    $fresh = agenticgraphSchema();

    clearPublishedMigrations();
    File::copyDirectory(__DIR__.'/../fixtures/migrations-v0.1.0', database_path('migrations'));
    publishAndMigrate();

    expect(agenticgraphSchema())->toBe($fresh);
});

it('guard: rolls every batch back and migrates again, on a fresh install and on an upgraded app', function (bool $upgrade) {
    if ($upgrade) {
        File::copyDirectory(__DIR__.'/../fixtures/migrations-v0.1.0', database_path('migrations'));
    }

    publishAndMigrate();
    $migrated = agenticgraphSchema();

    expect(Artisan::call('migrate:reset'))->toBe(0)
        ->and(Schema::hasTable('agenticgraph_executions'))->toBeFalse()
        ->and(Artisan::call('migrate'))->toBe(0)
        ->and(agenticgraphSchema())->toBe($migrated);
})->with(['fresh install' => false, 'upgraded from 0.1.0' => true]);

it('rolls the upgrade migrations back when their columns are already gone', function () {
    foreach (['zzzz_add_checkpoint_node_count_to_agenticgraph_executions_table', 'zzzz_add_execution_options_to_agenticgraph_executions_table', 'zzzz_add_stream_columns_to_agenticgraph_executions_table'] as $name) {
        $migration = include __DIR__."/../../database/migrations/{$name}.php";
        $migration->down();
        $migration->down();
    }

    expect(Schema::hasColumns('agenticgraph_executions', ['checkpoint_node_count', 'execution_options', 'stream_id', 'interrupted_at']))->toBeFalse();
});
