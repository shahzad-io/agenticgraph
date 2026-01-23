<?php

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Shahzad\AgenticGraph\Graph\GraphRegistry;

class VisualizeGraphCommand extends Command
{
    protected $signature = 'agenticgraph:visualize {name} {--format=mermaid} {--output=}';

    protected $description = 'Visualize an AgenticGraph graph to Mermaid or DOT';

    public function handle(): int
    {
        $name = $this->argument('name');
        $graph = GraphRegistry::get($name);
        if (! $graph) {
            $this->error("Graph not found: {$name}");

            return self::FAILURE;
        }
        $viz = $graph->visualize();
        $output = $this->option('output');
        if ($output) {
            file_put_contents($output, $viz);
            $this->info("Visualization written to {$output}");
        } else {
            $this->line($viz);
        }

        return self::SUCCESS;
    }
}
