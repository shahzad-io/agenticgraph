<?php

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Shahzad\AgenticGraph\Graph\GraphRegistry;

class ListGraphsCommand extends Command
{
    protected $signature = 'agenticgraph:list';

    protected $description = 'List registered AgenticGraph graphs';

    public function handle(): int
    {
        $graphs = GraphRegistry::all();
        if (empty($graphs)) {
            $this->warn('No graphs registered');

            return self::SUCCESS;
        }
        foreach ($graphs as $name => $graph) {
            $this->line("- {$name} ({$graph->description}) Nodes: ".count($graph->nodes));
        }

        return self::SUCCESS;
    }
}
