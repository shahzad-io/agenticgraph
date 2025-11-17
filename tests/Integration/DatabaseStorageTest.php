<?php

use Shahzad\AgenticGraph\Execution\Checkpoint;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Illuminate\Support\Str;

it('stores and retrieves checkpoint from database', function () {
    $storage = new DatabaseStorage();
    $c = new Checkpoint();
    $c->id = (string) Str::uuid();
    $c->graphName = 'g';
    $c->executionId = 'e';
    $c->state = new State(['value' => 42]);
    $c->currentNode = 'n';
    $c->createdAt = new DateTimeImmutable();
    $id = $storage->save($c);
    $retrieved = $storage->load($id);
    expect($retrieved)->not->toBeNull()
        ->and($retrieved->state->get('value'))->toBe(42);
});

it('lists and deletes checkpoints', function () {
    $storage = new DatabaseStorage();
    $c1 = new Checkpoint();
    $c1->id = (string) Str::uuid();
    $c1->graphName = 'gx';
    $c1->executionId = 'e1';
    $c1->state = new State();
    $c1->currentNode = 'n1';
    $c1->createdAt = new DateTimeImmutable();
    $storage->save($c1);
    $c2 = new Checkpoint();
    $c2->id = (string) Str::uuid();
    $c2->graphName = 'gx';
    $c2->executionId = 'e2';
    $c2->state = new State();
    $c2->currentNode = 'n2';
    $c2->createdAt = new DateTimeImmutable();
    $storage->save($c2);
    $list = $storage->list('gx');
    expect($list)->toHaveCount(2);
    $ok = $storage->delete($c1->id);
    expect($ok)->toBeTrue();
});