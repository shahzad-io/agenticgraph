<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use Shahzad\AgenticGraph\State\State;

class ExecuteGraphCommand extends Command
{
    protected $signature = 'agenticgraph:execute {name} {--state=}';

    protected $description = 'Execute an AgenticGraph graph synchronously with an optional JSON state';

    public function handle(GraphRegistry $registry): int
    {
        $name = $this->argument('name');
        $graph = $registry->get($name);
        if (! $graph) {
            $this->error("Graph not found: {$name}");

            return self::FAILURE;
        }
        $stateJson = $this->option('state') ?: '{}';
        $data = json_decode($stateJson, true) ?: [];
        $executor = app(GraphExecutor::class);
        $result = $executor->execute($graph, new State($data));
        $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
