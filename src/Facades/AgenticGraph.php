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
 * @method static \Shahzad\AgenticGraph\Contracts\StateContract execute(\Shahzad\AgenticGraph\Graph\Graph $graph, \Shahzad\AgenticGraph\Contracts\StateContract $state)
 * @method static string executeAsync(\Shahzad\AgenticGraph\Graph\Graph $graph, \Shahzad\AgenticGraph\Contracts\StateContract $state)
 * @method static \Shahzad\AgenticGraph\Contracts\StateContract|null resume(string $checkpointId)
 * @method static \Shahzad\AgenticGraph\Contracts\StateContract|null resumeWithInput(string $checkpointId, mixed $humanInput)
 * @method static array{executionId: string, checkpointId: string|null} executeUntil(\Shahzad\AgenticGraph\Graph\Graph $graph, \Shahzad\AgenticGraph\Contracts\StateContract $state, callable $predicate)
 * @method static \Shahzad\AgenticGraph\Contracts\StateContract executeWorkflow(string $name, \Shahzad\AgenticGraph\Contracts\StateContract $state)
 * @method static string executeAsyncWorkflow(string $name, \Shahzad\AgenticGraph\Contracts\StateContract $state)
 * @method static \Shahzad\AgenticGraph\Contracts\StateContract|null resumeWorkflow(string $checkpointId, mixed $humanInput)
 * @method static \Shahzad\AgenticGraph\Graph\Graph|null getOrBuild(string $name)
 *
 * @see \Shahzad\AgenticGraph\AgenticGraph
 *
 * @phpstan-ignore-next-line
 */
class AgenticGraph extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Shahzad\AgenticGraph\AgenticGraph::class;
    }
}
