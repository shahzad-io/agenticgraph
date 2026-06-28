<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Facades;

use Illuminate\Support\Facades\Facade;
use Shahzad\AgenticGraph\Tracing\Tracer;

/**
 * @method static mixed span(string $name, callable(): mixed $callback, array<string, mixed> &$attributes = [], string $spanType = 'custom')
 *
 * @see Tracer
 */
class Trace extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Tracer::class;
    }
}
