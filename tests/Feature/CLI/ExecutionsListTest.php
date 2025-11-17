<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('lists executions with timestamps and durations', function () {
    DB::table('agenticgraph_executions')->insert([
        'id' => 'exec-123',
        'graph_name' => 'g',
        'status' => 'completed',
        'initial_state' => json_encode([]),
        'final_state' => json_encode(['done' => true]),
        'duration' => 1.234,
        'started_at' => now(),
        'completed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $code = Artisan::call('agenticgraph:executions', ['--graph' => 'g', '--status' => 'completed', '--limit' => 20]);
    expect($code)->toBe(0);
});