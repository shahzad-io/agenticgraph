<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Shahzad\AgenticGraph\Storage\DatabaseStorage;
use Shahzad\AgenticGraph\Storage\FileStorage;
use Shahzad\AgenticGraph\Storage\RedisStorage;

class CheckpointDeleteCommand extends Command
{
    protected $signature = 'agenticgraph:checkpoint:delete {id}';

    protected $description = 'Delete a checkpoint by ID';

    public function handle(): int
    {
        $id = $this->argument('id');
        $storage = match (config('agenticgraph.storage', 'database')) {
            'redis' => app(RedisStorage::class),
            'file' => app(FileStorage::class),
            default => app(DatabaseStorage::class),
        };
        $ok = $storage->delete($id);
        $this->line($ok ? 'Deleted' : 'Not found');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
