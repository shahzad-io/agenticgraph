<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Shahzad\AgenticGraph\Execution\ExecutionRepository;

/**
 * A queued job's missing-row check reads the write connection: a replica that has not caught up must not make a job
 * skip a run that was just committed. Here the replica is an empty SQLite file, so any read sent to it fails.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/ag-split-'.bin2hex(random_bytes(6));
    mkdir($this->dir);
    touch($this->dir.'/write.sqlite');
    touch($this->dir.'/read.sqlite');

    config()->set('database.connections.writer', ['driver' => 'sqlite', 'database' => $this->dir.'/write.sqlite', 'prefix' => '']);
    config()->set('database.default', 'writer');
    foreach (File::allFiles(__DIR__.'/../../database/migrations') as $migration) {
        (include $migration->getRealPath())->up();
    }

    config()->set('database.connections.split', [
        'driver' => 'sqlite',
        'prefix' => '',
        'read' => ['database' => $this->dir.'/read.sqlite'],
        'write' => ['database' => $this->dir.'/write.sqlite'],
    ]);
    config()->set('database.default', 'split');
});

afterEach(function () {
    DB::purge('split');
    DB::purge('writer');
    config()->set('database.default', 'testing');
    File::deleteDirectory($this->dir);
});

it('finds the row through the write connection when the read connection is empty', function () {
    $repo = app(ExecutionRepository::class);
    $repo->queued('exec-split', 'split-graph');

    // Control: the split is in force, a plain read goes to the empty file.
    expect(fn () => DB::table('agenticgraph_executions')->where('id', 'exec-split')->exists())
        ->toThrow(QueryException::class, 'no such table')
        ->and($repo->exists('exec-split'))->toBeTrue()
        ->and($repo->exists('exec-never-written'))->toBeFalse();
});
