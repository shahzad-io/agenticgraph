<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Shahzad\AgenticGraph\Graph\GraphRegistry;

class ListGraphsCommand extends Command
{
    protected $signature = 'agenticgraph:list';

    protected $description = 'List registered AgenticGraph graphs';

    public function handle(GraphRegistry $registry): int
    {
        $graphs = $registry->all();
        $configGraphs = config('agenticgraph.graphs', []);
        $missingGraphs = [];

        foreach ($configGraphs as $name => $class) {
            if (isset($graphs[$name])) {
                continue;
            }
            $graph = $registry->getOrBuild($name);
            if ($graph) {
                $graphs[$name] = $graph;
            } else {
                $missingGraphs[$name] = $class;
            }
        }

        if (empty($graphs) && empty($missingGraphs)) {
            $this->warn('No graphs registered');

            return self::SUCCESS;
        }
        foreach ($graphs as $name => $graph) {
            $description = $graph->description !== '' ? " ({$graph->description})" : '';
            $this->line("- {$name}{$description} Nodes: ".count($graph->nodes));
        }
        foreach ($missingGraphs as $name => $class) {
            $this->line("- {$name} (config: {$class}) Nodes: n/a");
        }

        return self::SUCCESS;
    }
}
