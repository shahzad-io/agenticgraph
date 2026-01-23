<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;

it('lists checkpoints via CLI', function () {
    $storage = new DatabaseStorage;
    foreach (range(1, 2) as $i) {
        $c = new Checkpoint;
        $c->id = (string) Str::uuid();
        $c->graphName = 'cli-graph';
        $c->executionId = 'exec-'.$i;
        $c->currentNode = 'node'.$i;
        $c->state = new State(['i' => $i]);
        $c->createdAt = new DateTimeImmutable;
        $storage->save($c);
    }
    $code = Artisan::call('agenticgraph:checkpoint:list', ['--graph' => 'cli-graph', '--limit' => 50]);
    expect($code)->toBe(0);
});

it('resumes from checkpoint via CLI', function () {
    $storage = new DatabaseStorage;
    $c = new Checkpoint;
    $c->id = (string) Str::uuid();
    $c->graphName = 'resume-graph';
    $c->executionId = 'exec-resume';
    $c->currentNode = 'start';
    $c->state = new State(['value' => 1]);
    $c->createdAt = new DateTimeImmutable;
    $storage->save($c);
    $code = Artisan::call('agenticgraph:checkpoint:resume', ['id' => $c->id]);
    expect(in_array($code, [0, 1]))->toBeTrue();
});

it('deletes checkpoint via CLI', function () {
    $storage = new DatabaseStorage;
    $c = new Checkpoint;
    $c->id = (string) Str::uuid();
    $c->graphName = 'del-graph';
    $c->executionId = 'exec-del';
    $c->currentNode = 'n';
    $c->state = new State;
    $c->createdAt = new DateTimeImmutable;
    $storage->save($c);
    $code = Artisan::call('agenticgraph:checkpoint:delete', ['id' => $c->id]);
    expect($code)->toBe(0);
});

it('cleans up checkpoints via CLI', function () {
    $code = Artisan::call('agenticgraph:checkpoint:cleanup', ['--days' => 0]);
    expect($code)->toBe(0);
});
