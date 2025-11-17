<?php

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;

class CheckpointDeleteCommand extends Command
{
    protected $signature = 'agenticgraph:checkpoint:delete {id}';
    protected $description = 'Delete a checkpoint by ID';

    public function handle(): int
    {
        $id = $this->argument('id');
        $storage = match (config('agenticgraph.storage')) {
            'redis' => app(\Shahzad\AgenticGraph\Storage\RedisStorage::class),
            'file' => app(\Shahzad\AgenticGraph\Storage\FileStorage::class),
            default => app(\Shahzad\AgenticGraph\Storage\DatabaseStorage::class),
        };
        $ok = $storage->delete($id);
        $this->line($ok ? 'Deleted' : 'Not found');
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}