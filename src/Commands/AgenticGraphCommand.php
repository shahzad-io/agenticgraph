<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Commands;

use Illuminate\Console\Command;

class AgenticGraphCommand extends Command
{
    public $signature = 'agenticgraph';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
