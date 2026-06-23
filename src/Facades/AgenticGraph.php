<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Shahzad\AgenticGraph\Graph\GraphRegistry registry()
 * @method static \Shahzad\AgenticGraph\Execution\GraphExecutor executor()
 * @method static \Shahzad\AgenticGraph\Graph\GraphBuilder create(string $name)
 * @method static void register(string $name, \Shahzad\AgenticGraph\Graph\Graph $graph)
 * @method static \Shahzad\AgenticGraph\Graph\Graph|null get(string $name)
 * @method static \Shahzad\AgenticGraph\Execution\ExecutionHandle execute(string $name, \Shahzad\AgenticGraph\Contracts\StateContract $state, \Shahzad\AgenticGraph\Execution\ExecutionOptions $options)
 * @method static \Shahzad\AgenticGraph\Execution\ExecutionHandle run(string $name, \Shahzad\AgenticGraph\Contracts\StateContract $state)
 * @method static \Shahzad\AgenticGraph\Execution\ExecutionHandle dispatch(string $name, \Shahzad\AgenticGraph\Contracts\StateContract $state)
 * @method static \Shahzad\AgenticGraph\Execution\ExecutionHandle stream(string $name, \Shahzad\AgenticGraph\Contracts\StateContract $state, list<string>|null $nodes = null, list<string>|null $events = null, bool $inline = false)
 * @method static \Shahzad\AgenticGraph\Execution\ExecutionHandle broadcast(string $name, \Shahzad\AgenticGraph\Contracts\StateContract $state, list<string>|null $nodes = null, list<string>|null $events = null, bool $async = true)
 * @method static \Shahzad\AgenticGraph\Execution\ExecutionHandle getExecution(string $executionId)
 * @method static \Shahzad\AgenticGraph\Execution\ResumeResult resume(string $executionId, mixed $humanInput)
 * @method static \Shahzad\AgenticGraph\Execution\InterruptStatus interruptDetails(string $executionId)
 * @method static \Shahzad\AgenticGraph\Graph\Graph|null getOrBuild(string $name)
 *
 * @see \Shahzad\AgenticGraph\AgenticGraph
 */
class AgenticGraph extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Shahzad\AgenticGraph\AgenticGraph::class;
    }
}
