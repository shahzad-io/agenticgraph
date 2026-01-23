<?php

use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Storage\FileStorage;

it('stores and retrieves checkpoint in files', function () {
    $storage = new FileStorage(sys_get_temp_dir().'/agenticgraph-tests');
    $c = new Checkpoint;
    $c->id = 'file-1';
    $c->graphName = 'g';
    $c->executionId = 'e';
    $c->state = new State(['value' => 1]);
    $c->currentNode = 'n';
    $c->createdAt = new DateTimeImmutable;
    $storage->save($c);
    $r = $storage->load('file-1');
    expect($r->state->get('value'))->toBe(1);
    $list = $storage->list('g');
    expect($list)->toContain('file-1');
    $ok = $storage->delete('file-1');
    expect($ok)->toBeTrue();
});
