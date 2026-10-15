---
name: agenticgraph-core
description: "Build AgenticGraph agent graphs in Laravel: GraphBuilder, nodes (AbstractNode, PrismNode), state, edges, routers, cyclic and parallel graphs, interrupts and checkpoints, MCP tools, middleware, lifecycle events, the CLI, and testing graphs and nodes."
---

# AgenticGraph Core

docs-sha: 301ce71

Condensed from the AgenticGraph docs (`/docs/...` paths below) at that commit. When a detail here and the package source disagree, the source wins.

Other skills, use them when installed: `agenticgraph-streaming` for `stream()`, `broadcast()`, SSE and the JS clients. `agenticgraph-tracing` for traces, spans and AgenticPulse. `agenticgraph-vector` for retrieval and vector stores, if `shahzad/agenticgraph-vector` is installed.

## Install

```bash
composer require shahzad/agenticgraph
php artisan vendor:publish --tag=agenticgraph-config
php artisan vendor:publish --tag=agenticgraph-migrations
php artisan migrate
php artisan agenticgraph:list
```

- Migrations are required before the first run. Every run writes a row to `agenticgraph_executions`. They create `agenticgraph_executions`, `agenticgraph_checkpoints`, `agenticgraph_interrupts` and `stream_events`.
- Package discovery registers the provider and the `AgenticGraph` and `Trace` facades. Prism and Relay come with it.
- There is no `agenticgraph:install`.

Docs: `/docs/getting-started`

## Env basics

```dotenv
AGENTICGRAPH_PRISM_MODEL=openai:gpt-4o-mini   # example; pick your own provider:model
AGENTICGRAPH_STORAGE=database
AGENTICGRAPH_CHECKPOINT_ENABLED=true
AGENTICGRAPH_QUEUE_CONNECTION=database
AGENTICGRAPH_QUEUE_NAME=agenticgraph
```

- `AGENTICGRAPH_PRISM_MODEL` has no default. Set it, or `$model` on the node or router, as `provider:model`. A missing model or a model without a provider throws `MissingModelException` on the first Prism call. The provider key (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`) is Prism's env.
- `AGENTICGRAPH_STORAGE`: `database` (default), `redis` or `file`. Same value in every environment, or resume cannot find snapshots.
- `AGENTICGRAPH_QUEUE_CONNECTION` defaults to `redis`. It matters only for async runs, async fan-out and async resume. Run a worker on the graph queue: `php artisan queue:work --queue=agenticgraph`.
- `AGENTICGRAPH_MAX_ITERATIONS` (1000) caps a run.

Docs: `/docs/getting-started`

## The model

- **State** is an immutable bag of keys. `set()` returns a new instance.
- A **node** does one job: state in, new state out. It never picks the next node.
- An **edge** names the next node. A **router** picks it from state.
- The **coordinator** loops: interrupt check, run node, checkpoint, exit or route.
- `AgenticGraph::execute()` is the one entry point. `run()`, `dispatch()`, `stream()` and `broadcast()` are presets of `ExecutionOptions`.

Docs: `/docs/graph`, `/docs/execution`

## Build and register a graph

A graph in config is a class with a static `build()` that returns a `Graph`.

```php
namespace App\Graphs;

use App\Nodes\DraftNode;
use App\Nodes\PublishNode;
use App\Routers\QualityRouter;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;

final class ContentGraph
{
    public static function build(): Graph
    {
        return GraphBuilder::create('content')
            ->description('Draft, review, publish')
            ->addNode('draft', DraftNode::class)
            ->addNode('publish', PublishNode::class)
            ->setEntryPoint('draft')
            ->addConditionalEdge('draft', QualityRouter::class)
            ->addExitPoint('publish')
            ->build();
    }
}
```

```php
// config/agenticgraph.php
'graphs' => [
    'content' => \App\Graphs\ContentGraph::class,
],
```

- `build()` throws `InvalidGraphException` without an entry point or an exit, when a node is unreachable from the entry, or when no exit is reachable. Reachability follows each router's `getAvailableRoutes()`, so list every node a router can return.
- `AgenticGraph::register($name, $graph)` registers at runtime (tests, service providers). Config graphs are built on first use by `AgenticGraph::getOrBuild()`.
- Resume and async jobs rebuild graphs by name. Keep production graphs in config.

Docs: `/docs/graph`, `/docs/api/graph`

## Nodes

Use `AbstractNode` for deterministic work (validation, math, DB, HTTP). Use `PrismNode` when language is the point.

```php
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\AbstractNode;

final class ValidateOrderNode extends AbstractNode
{
    public function __construct(private \App\Services\Inventory $inventory) {}

    public function execute(StateContract $state): StateContract
    {
        $inStock = $this->inventory->has((string) $state->get('order.sku'));

        return $state->set('order.in_stock', $inStock);
    }
}
```

- Nodes are resolved from the container, so constructor injection works.
- `protected ?string $name` overrides the node name used in events and spans.
- Retries: `getRetryAttempts()` (3), `getRetryDelay()` (1000 ms), `shouldRetry($e)`. Override them on the node.
- Hooks: `onBefore`, `onAfter`, `onError`. Retries wrap `onBefore → execute → onAfter`.
- `getTimeout()` exists on the interface, but the coordinator never reads it.

`PrismNode` helpers (all `protected`):

| Helper | Returns | Use |
|---|---|---|
| `prompt($template, $vars, $additionalContent)` | `string` | One-off text. Streams when the run streams. |
| `promptTemplate(PromptTemplate, ...)` / `chatPrompt(ChatPromptTemplate, ...)` / `promptMessages($messages)` | `string` | Reusable templates and role messages |
| `structured($prompt, $schema)` | `mixed` | Root schema should be an `ObjectSchema`. Pass a string, not a template. |
| `tools($prompt, $tools)` | `ToolCall[]` | One step. Not a chat reply. |
| `agent($prompt, $tools, $options)` | `TextResponse` | Multi-step tool loop. `maxSteps` defaults to 5; `systemPrompt` option. Read `->text`. |
| `withMcp($server, $options)` | `McpRequest` | Tools from a Relay MCP server |
| `prism()` | `PendingTextRequest` | Raw Prism request. Tools added with `withTools()` here are not traced. |
| `embeddingsFromInput`, `generateImage`, `audioToText`, `textToAudio` | Prism responses | Media |
| `withModel($model)`, `withCache($ttlMinutes)`, `withoutCache()` | `static` | Per-call overrides |

- `protected ?string $model` sets the node's default model. `protected bool $trackTokens = false` stops `TokensUsed`.
- `protected array $providerOptions` is sent with every text, structured and streamed call, for example `['use_tool_calling' => true]` for Anthropic structured output on models older than Claude Sonnet 4.5.
- Full `structured()` and `agent()` examples: [references/prism-nodes.md](references/prism-nodes.md).

Docs: `/docs/nodes`, `/docs/api/nodes`

## Prompts

- `{word}` is a placeholder. `{{` and `}}` are literal braces. `{user.name}` is not a path; flatten in PHP.
- A missing variable throws `TemplateException`.
- `PromptTemplate::create($template)->withDefaults([...])->withRequired([...])`, then `format($vars)`.
- `ChatPromptTemplate` keeps role messages. `formatAsString()` is for cache keys and logs, not the request.

Docs: `/docs/prompts`, `/docs/api/prompts`

## State

```php
use Shahzad\AgenticGraph\State\State;

$state = new State(['topic' => 'Laravel']);
$state = $state->set('draft', $text);   // capture the return
$state->get('missing', 'default');
```

- `set()`, `forget()` and `merge()` return new instances. A discarded return is a lost write.
- `merge()` takes another `StateContract`, not an array. Lists replace, nested maps combine.
- `State` is `final`. Put structure in keys, not a subclass.
- Store plain data. Mutating objects in place breaks diffs and checkpoints.

Docs: `/docs/state`, `/docs/api/state`

## Edges and routers

- `addEdge($from, $to)`: a fixed next hop. Only the first outgoing edge of a node is used, so two `addEdge` calls are not a fork.
- `addConditionalEdge($from, $router)`: a `RouterInterface` instance or class name. A closure is not accepted.
- One decision per node. Put a second decision on a later node.
- Every route key must be a node id. `build()` follows `getAvailableRoutes()` to decide which nodes are reachable.

```php
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Routers\AbstractRouter;

final class QualityRouter extends AbstractRouter
{
    protected array $routes = [
        'publish' => 'Good enough',
        'draft' => 'Needs another pass',
    ];

    protected ?string $defaultRoute = 'draft';

    public function route(StateContract $state): string|array
    {
        return $state->get('score', 0) >= 80 ? 'publish' : 'draft';
    }
}
```

- `$routes` is `node id => description`, not a list of names.
- `PrismRouter` asks the model to pick one route from the descriptions. It never fans out. To add rules, override `buildPrompt()`, not `route()`.
- More router shapes (PrismRouter subclass, loop-aware, fan-out): [references/routers.md](references/routers.md).

Docs: `/docs/edges`, `/docs/routing`

## Cyclic graphs

- A loop is an edge or route that points back.
- The exit must be a separate node the router can name. Do not mark the looping node as the exit: after it runs, the graph ends.
- Count iterations in state, or implement `LoopAwareRouterInterface` and read `$context->getNodeVisitCount('draft')` in `routeWithContext()`.
- Without a cap the loop runs until the iteration limit throws, and the run fails.
- `cycle_detection=error` rejects cycles at `build()`. Leave it `silent` for graphs meant to loop.

Docs: `/docs/cyclic`

## Parallel execution

- A router returns an array of node ids to fan out. Then `addJoin(['write', 'seo'], 'merge')` waits for all of them.
- Without `addJoin`, nothing waits for every branch.
- Each branch starts from the same snapshot. Write unique keys per branch. Colliding scalar keys are last-write-wins, with a warning.
- Sync runs branches one after another in this process. Async sends one job per branch on the graph queue.

Docs: `/docs/parallel`

## Run a graph

```php
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\State\State;

$handle = AgenticGraph::run('content', new State(['topic' => 'Laravel']));   // sync
$final = $handle->getFinalState();

$handle = AgenticGraph::dispatch('content', new State(['topic' => 'Laravel'])); // async ticket
$handle = AgenticGraph::execute('content', new State, new ExecutionOptions(mode: ExecutionMode::ASYNC, interrupts: false));
```

- `interrupts` defaults to `true`. A graph with a gate pauses unless you pass `interrupts: false`.
- An async handle is a ticket. `getFinalState()` throws `NotYetCompleteException` until it finishes. Use `wait()`, or `AgenticGraph::getExecution($id)` later.
- Handle: `id()`, `status()` (`ExecutionStatus`), `isCompleted()`, `isInterrupted()`, `isFailed()`, `error()`, `latestState()`, `refresh()`.
- There is no timeout on `ExecutionOptions`. Limits live in config.
- Streaming presets and `StreamingMode`: use the `agenticgraph-streaming` skill.

Docs: `/docs/execution`, `/docs/api/execution`

## Interrupts (human in the loop)

- A gate is a `PrismNode` that overrides `shouldInterrupt()`. It is checked before `execute()`, so the gate pauses on data earlier nodes wrote. `AbstractNode` gates are never asked.
- The run stops with status `INTERRUPTED`, which is not a failure. Override `getInterruptReason()` and `getInterruptPayload()` for the reviewer, and `onResume($state, $humanInput)` to apply the answer. `handleApproval()` writes `approved` and `approval_comments`.
- Same request: `$handle->resume($input)`. Later request: `AgenticGraph::resume($executionId, $input)`, which returns a `ResumeResult` (`->handle`, `->interrupt`). It needs interrupt persistence (on by default) and throws without it.
- `AgenticGraph::interruptDetails($executionId)` returns the pending interrupt and latest state for your UI.
- Gate and controller example: [references/interrupts.md](references/interrupts.md).

Docs: `/docs/interrupts`

## Checkpoints

- The coordinator writes a snapshot after each node (`checkpoint.frequency`) and before a gate. Nodes never call it.
- `checkpoint.enabled` (snapshots) and `interrupts.persistence_enabled` (resume in a later request) are separate settings.
- `$handle->checkpoint()` returns the latest `Checkpoint`. `StateManager::rollback($executionId, $checkpointId)` returns that snapshot's `State`; the graph does not walk backwards.
- A worker restart retries the job. It does not load the latest checkpoint by itself.

Docs: `/docs/checkpoints`

## MCP tools

```php
use Prism\Prism\Enums\ToolChoice;

$mcp = $this->withMcp('context7', ['toolChoice' => ToolChoice::Any, 'maxSteps' => 5])
    ->withSystemPrompt($systemPrompt)
    ->withPrompt($question)
    ->asMcp();

return $state->set('answer', $mcp->cleanText())->set('tool_calls', $mcp->toolCalls());
```

- The server name is a key in `config/relay.php` (`vendor:publish --tag=relay-config`).
- Prism's default `maxSteps` is 1 and `withMcp` does not raise it. Without `maxSteps` the model cannot call a tool and then answer.
- `McpResponse` lives in `Shahzad\AgenticGraph\Mcp`. `withMcp` always uses `asText()`; it never streams.
- On servers, set `RELAY_NPX_PATH` and `PATH` in the env.

Docs: `/docs/mcp`, `/docs/api/mcp`

## Middleware

- Configured only in `config('agenticgraph.middleware.global')`, never on `GraphBuilder`. The first entry is outermost.
- No interface. A class with `handle(StateContract $state, NodeInterface $node, Closure $next): StateContract`. Not Laravel HTTP middleware.
- The defaults are logging, timing and validation. `ValidationMiddleware` calls `$state->validate()` before and after each node.
- `CachingMiddleware` and `CircuitBreakerMiddleware` are opt-in and apply to every node name. Nodes without `isCacheable()` get cached, including LLM nodes. The circuit breaker is per node name, process-wide.
- Retries loop inside `$next`. Your `handle()` runs once per node, not once per attempt.

Docs: `/docs/guide-middleware`

## Lifecycle events

Ordinary Laravel events in `Shahzad\AgenticGraph\Events`. Not stream events, not spans.

- The ones to listen for: `GraphExecutionFailed` (`exception`), `TokensUsed` (`model`, `totalTokens`, `callType`), `InterruptPending` (`interruptId`, `checkpointId`, `payload`).
- Also: `GraphExecutionStarted`, `GraphExecutionCompleted`, `NodeExecutionStarted`, `NodeExecutionCompleted`, `NodeExecutionFailed` (each attempt), `NodeInterrupted`, `NodeResumed`, `CheckpointCreated`, `RouteDecided`, `ToolCallCaptured`, `CycleCompleted`.
- Listeners run in the process that dispatched the event. To queue one, put `ShouldQueue` on the listener; on the event class it does nothing.
- `InterruptPending` needs interrupt persistence. `AGENTICGRAPH_INTERRUPT_BROADCAST` only toggles whether it is dispatched. It is not Echo.
- Do not add `broadcastOn()` to package events. Broadcast from your listener.

Docs: `/docs/guide-events`

## CLI

| Command | Notes |
|---|---|
| `agenticgraph:list` | Builds config graphs (`getOrBuild`) and lists them |
| `agenticgraph:execute {name} --state='{...}'` | Sync. Prints the final state as JSON. Uses `get`, so a never-built config graph is "not found". Bad JSON becomes `[]`. |
| `agenticgraph:visualize {name} --output=` | Mermaid only. `--format` is ignored. |
| `agenticgraph:executions --graph= --status= --limit=` | Recent runs |
| `agenticgraph:checkpoint:list`, `:delete {id}`, `:cleanup --days=30` | Snapshots |
| `agenticgraph:checkpoint:resume {id}` | Replays from a snapshot. Not human-in-the-loop resume. |
| `agenticgraph:pricing:sync` | Rewrites package model pricing. Not a daily command. |

Docs: `/docs/guide-cli`

## Testing

- Test node logic by calling `execute()` on the node with a `State`. No graph needed.
- Fake the model: `Prism::fake([TextResponseFake::make()->withText('...')])`. One fake per model call, in order.
- Test a graph with `AgenticGraph::run()`. Assert `status()` and `getFinalState()`. `GraphExecutor::execute()` returns `State`, not a handle.
- Interrupts: sync run, assert `isInterrupted()` and `interruptedAtNode()`, then `$handle->resume([...])`.
- Async: `Bus::fake()`, `AgenticGraph::dispatch()`, assert the job. `Event::fake()` swallows every listener.
- Examples: [references/testing.md](references/testing.md). Trace tests: use the `agenticgraph-tracing` skill.

Docs: `/docs/guide-testing`

## Troubleshooting

| Symptom | Cause |
|---|---|
| `no such table: agenticgraph_executions` | Migrations not run |
| `Graph '...' not found in registry or config` | Not in `config('agenticgraph.graphs')`, or the class has no static `build()` |
| Run stops with `Max iterations reached` | A loop has no reachable exit or no cap |
| One parallel branch's output missing | Same key written by two branches |
| Async run never finishes | No worker on the graph queue and connection |
| Resume finds nothing after deploy | Different `AGENTICGRAPH_STORAGE`, or persistence off |

Docs: `/docs/guide-troubleshooting`
