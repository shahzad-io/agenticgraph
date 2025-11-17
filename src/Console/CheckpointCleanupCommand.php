<?php

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;

class CheckpointCleanupCommand extends Command
{
    protected $signature = 'agenticgraph:checkpoint:cleanup {--days=30}';
    protected $description = 'Cleanup old checkpoints';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $storage = match (config('agenticgraph.storage')) {
            'redis' => app(\Shahzad\AgenticGraph\Storage\RedisStorage::class),
            'file' => app(\Shahzad\AgenticGraph\Storage\FileStorage::class),
            default => app(\Shahzad\AgenticGraph\Storage\DatabaseStorage::class),
        };
        $count = $storage->cleanup($days);
        $this->line((string) $count);
        return self::SUCCESS;
    }
}