<?php

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;

class CheckpointListCommand extends Command
{
    protected $signature = 'agenticgraph:checkpoint:list {--graph=} {--limit=50}';
    protected $description = 'List checkpoints for a graph';

    public function handle(): int
    {
        $graph = $this->option('graph') ?? '';
        $limit = (int) $this->option('limit');
        $storage = match (config('agenticgraph.storage')) {
            'redis' => app(\Shahzad\AgenticGraph\Storage\RedisStorage::class),
            'file' => app(\Shahzad\AgenticGraph\Storage\FileStorage::class),
            default => app(\Shahzad\AgenticGraph\Storage\DatabaseStorage::class),
        };
        $list = $storage->list($graph, $limit);
        foreach ($list as $id) {
            $this->line($id);
        }
        return self::SUCCESS;
    }
}