<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Shahzad\AgenticGraph\AgenticGraph
 */
class AgenticGraph extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Shahzad\AgenticGraph\AgenticGraph::class;
    }
}
