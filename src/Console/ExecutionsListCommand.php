<?php

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExecutionsListCommand extends Command
{
    protected $signature = 'agenticgraph:executions {--graph=} {--status=} {--limit=20}';

    protected $description = 'List AgenticGraph executions';

    public function handle(): int
    {
        $graph = $this->option('graph');
        $status = $this->option('status');
        $limit = (int) $this->option('limit');
        $query = DB::table('agenticgraph_executions')->orderByDesc('created_at');
        if ($graph) {
            $query->where('graph_name', $graph);
        }
        if ($status) {
            $query->where('status', $status);
        }
        $rows = $query->limit($limit)->get();
        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s | %s | %s | started: %s | completed: %s | duration: %s',
                $row->id,
                $row->graph_name,
                $row->status,
                $row->started_at ?: '-',
                $row->completed_at ?: '-',
                $row->duration !== null ? number_format($row->duration, 3).'s' : '-'
            ));
        }

        return self::SUCCESS;
    }
}
