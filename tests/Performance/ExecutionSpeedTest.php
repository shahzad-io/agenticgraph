<?php

it('executes 100-node graph under 5 seconds', function () {
    $graph = createLargeGraph(100);
    $start = microtime(true);
    (new \Shahzad\AgenticGraph\Execution\GraphExecutor)->execute($graph, createTestState());
    $duration = microtime(true) - $start;
    expect($duration)->toBeLessThan(5.0);
});
