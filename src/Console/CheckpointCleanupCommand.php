<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Storage\RedisStorage;

class CheckpointCleanupCommand extends Command
{
    protected $signature = 'agenticgraph:checkpoint:cleanup {--days=30}';

    protected $description = 'Cleanup old checkpoints';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $storage = match (config('agenticgraph.storage', 'database')) {
            'redis' => app(RedisStorage::class),
            'file' => app(FileStorage::class),
            default => app(DatabaseStorage::class),
        };
        $count = $storage->cleanup($days);
        $this->line((string) $count);

        return self::SUCCESS;
    }
}
