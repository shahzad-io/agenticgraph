<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Shahzad\AgenticGraph\Execution\GraphExecutor;

class CheckpointResumeCommand extends Command
{
    protected $signature = 'agenticgraph:checkpoint:resume {id}';

    protected $description = 'Resume a graph from a checkpoint';

    public function handle(): int
    {
        $id = $this->argument('id');
        $executor = app(GraphExecutor::class);
        try {
            $state = $executor->resume($id);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($state->toArray(), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
