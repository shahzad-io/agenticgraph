<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Storage\RedisStorage;

class CheckpointListCommand extends Command
{
    protected $signature = 'agenticgraph:checkpoint:list {--graph=} {--limit=50}';

    protected $description = 'List checkpoints for a graph';

    public function handle(): int
    {
        $graph = $this->option('graph') ?? '';
        $limit = (int) $this->option('limit');
        $storage = match (config('agenticgraph.storage', 'database')) {
            'redis' => app(RedisStorage::class),
            'file' => app(FileStorage::class),
            default => app(DatabaseStorage::class),
        };
        $list = $storage->list($graph, $limit);
        foreach ($list as $id) {
            $this->line($id);
        }

        return self::SUCCESS;
    }
}
