# Testing examples

Docs: `/docs/guide-testing`

## A node, no graph

```php
use App\Nodes\CalculateTaxNode;
use Shahzad\AgenticGraph\State\State;

it('adds tax', function () {
    $result = (new CalculateTaxNode)->execute(new State(['subtotal' => 100]));

    expect($result->get('total'))->toBe(110);
});
```

## A PrismNode with a faked model

`Llm::fake()` queues the replies, one per provider request, in order, and records what each call resolved.

```php
use App\Nodes\SummarizeNode;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Testing\Llm;

it('writes the faked text onto state', function () {
    $llm = Llm::fake(['Hello John']);

    $result = (new SummarizeNode)->execute(new State(['name' => 'John']));

    expect($result->get('text'))->toContain('Hello John');
    $llm->assertPrompted(fn (array $call) => $call['vars']['name'] === 'John');
    $llm->assertSettings(['model' => ['value' => 'openai:gpt-4o-mini', 'source' => 'node']]); // example model
    $llm->assertNotInterpolated('history');
});
```

A queued array is structured data, a `Throwable` is a failed attempt (retry tests), a Prism `TextResponseFake` passes through (tool calls, usage). A structured retry or a tool step takes the next entry. Routers are recorded too (`node` is `PrismRouter`). `$llm->prism()` is the Prism fake underneath.

## A graph

```php
use App\Nodes\EndNode;
use App\Nodes\StartNode;
use Shahzad\AgenticGraph\Execution\ExecutionStatus;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\State\State;

it('reaches the exit', function () {
    AgenticGraph::register('order-flow', GraphBuilder::create('order-flow')
        ->addNode('start', StartNode::class)
        ->addNode('end', EndNode::class)
        ->addEdge('start', 'end')
        ->setEntryPoint('start')
        ->addExitPoint('end')
        ->build());

    $handle = AgenticGraph::run('order-flow', new State);

    expect($handle->status())->toBe(ExecutionStatus::COMPLETED)
        ->and($handle->getFinalState()->get('status'))->toBe('completed');
});
```

Graphs in `config('agenticgraph.graphs')` need no `register`. Isolated tests register a small graph so they do not depend on the app's catalog.

## An interrupt

```php
$handle = AgenticGraph::run('approval-flow', new State(['draft' => 'Sample draft']));

expect($handle->isInterrupted())->toBeTrue()
    ->and($handle->interruptedAtNode())->toBe('await_approval');

$resumed = $handle->resume(['approved' => true, 'comments' => 'LGTM']);

expect($resumed->isCompleted())->toBeTrue()
    ->and($resumed->getFinalState()->get('approved'))->toBeTrue();
```

## Async

```php
use Illuminate\Support\Facades\Bus;
use Shahzad\AgenticGraph\Jobs\ExecuteGraphJob;

Bus::fake();

$handle = AgenticGraph::dispatch('order-flow', new State);

expect($handle->status())->toBe(ExecutionStatus::QUEUED);
Bus::assertDispatched(ExecuteGraphJob::class);
```

## Pitfalls

- `getFinalState()` on a queued handle throws `NotYetCompleteException`.
- `app(GraphExecutor::class)->execute($graph, $state)` returns `State`, not a handle.
- `Event::fake()` stops every listener, including tracing.
