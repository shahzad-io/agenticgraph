---
name: agenticgraph-core
description: "Build AgenticGraph agent graphs in Laravel: GraphBuilder, nodes (AbstractNode, PrismNode), state, edges, routers, cyclic and parallel graphs, interrupts and checkpoints, MCP tools, middleware, lifecycle events, the CLI, and testing graphs and nodes."
---

# AgenticGraph Core

docs-sha: 34f68f1

Condensed from the AgenticGraph docs (`/docs/...` paths below) at that commit. When a detail here and the package source disagree, the source wins.

Other skills, use them when installed: `agenticgraph-streaming` for `stream()`, `broadcast()`, SSE and the JS clients. `agenticgraph-tracing` for traces, spans and AgenticPulse. `agenticgraph-vector` for retrieval and vector stores, if `shahzad/agenticgraph-vector` is installed.

## Rules

1. Model and provider names live in config (`AGENTICGRAPH_PRISM_MODEL`) or in the node property `$model`. Names in this skill are examples, labelled as such. `->model()` on a call is an exception: say why in a comment.
2. Visitor text reaches a model only through `vars()` on a template, or through `history()`. Never put it in a template string, a `system()` string or `user()`.
3. Dynamic text (model output, JSON, user input) goes in `vars()`, never in the template string.
4. Chat replies: call `->withoutCache()`. Caching is off by default, and tool and MCP requests never cache.
5. A string passed to `prompt()` is a template. Never join or interpolate runtime values (visitor text, model output, retrieved content) into prompt text; pass them through `vars()` or `history()`. Write literal braces as `{{` and `}}`.
6. Two templates in one request throw. So do a schema with tools, and a request with only `system()`.
7. On reasoning models `$maxTokens` includes the reasoning tokens: a limit that suits a normal model can give an empty reply (streamed) or a `max tokens exceeded` error (OpenAI, not streamed and structured). A streamed call cut by the limit returns `length` with the real usage. Set the reasoning level on the node (`protected ?string $reasoning`, one of `off`, `low`, `medium`, `high`) or with `->reasoning()` on one request; never in config, and never through raw `$providerOptions` when a level covers it.
8. Test LLM nodes and routers with `Llm::fake()` (`Shahzad\AgenticGraph\Testing\Llm`), not `Prism::fake()`: it asserts what the node resolved (vars, settings with their sources, prompt file, history). Keep `Prism::fake()` for code that calls Prism directly.

## Task: install and configure

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

## Task: build a graph

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

## Task: write a node

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
- Nodes have no timeout setting. Bound a model call with `->timeout()`, a queued run with `AGENTICGRAPH_EXECUTION_TIMEOUT`.

### Model calls in a PrismNode

Every model call starts with `$this->llm()`. Each builder method returns a new `LlmRequest`; the node is never changed.

```php
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

final class SummaryNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $summary = $this->llm()
            ->system('You write two-sentence summaries.')
            ->prompt('Summarize: {text}')
            ->vars(['text' => (string) $state->get('text')])
            ->text();

        return $state->set('summary', $summary);
    }
}
```

| Helper | Returns | Use |
|---|---|---|
| `llm()` | `LlmRequest` | Every model call: text, chat, structured, tools, MCP. End with `text()`, `structured()` or `run()`. |
| `prism()` | `PendingTextRequest` | Raw Prism request with the node's settings. Not traced, cached or streamed. |
| `embeddingsFromInput`, `generateImage`, `audioToText`, `textToAudio` | Prism responses | Media |

- Inputs: `prompt($stringOrPromptTemplate)` or `chat(ChatPromptTemplate)`, plus `vars()`. Templates interpolate; `system()`, `user()`, `assistant()` and `history()` are literal. Rules 2, 3 and 5 apply.
- `user()` and `assistant()` append after `chat()` and throw with `prompt()`. `attach($media)` adds media to the last user turn.
- Retrieved documents go through `context($items, as: 'context', format: ?PromptTemplate, separator: "\n\n")`, never through a hand-built string in `vars()`. `$items`: a list of `id`/`content`/`metadata`/`score` arrays, or any object with `toContextItems()` (a vector `SearchResult`; core never imports vector). `format:` renders each item from its metadata plus `id`, `content`, `score` (missing key throws). The joined text is a value for `{context}`, never interpolated; the same name in `vars()` throws. `run()->sources()` returns the items (`id`, `metadata`, `score`); the llm span gets `context.count` and `context.ids` (first 20); `explain()['context']` shows the text and count. Store `toContextItems()` (plain arrays) on state when retrieval and generation are separate nodes.
- `schema($schema)` then `structured(): array`; the root schema should be an `ObjectSchema`. A schema with tools throws. `->as(Dto::class)->data()` hydrates the reply into a class (reflection; `#[ListOf]` for lists; errors name the path). Constrain values in the schema first (enums, required fields; measured 3 of 20 calls retried without the enum, 0 of 20 with it); the retry (`agenticgraph.prism.structured.retries`, default `1`, error fed back; `->structuredRetries($n)` for one call) is a safety net. `->structuredMode('json')` is OpenAI only and throws elsewhere. `run()->usage` sums every attempt and is null when one attempt's usage is unknown.
- `tools()`, `mcp($server)`, `toolChoice()`, `maxSteps()`: one step and Prism's tool choice by default. Raise `maxSteps` for a tool loop. `run()` returns an `LlmResponse` with the tool calls and results of every step and `steps` (each step's text, tool calls, results, usage, finish reason); `text` is the final step's.
- Settings: config, then node properties (`$model`, `$maxTokens`, `$temperature`, `$providerOptions`, `$trackTokens`), then the call. Provider options have no config layer. `->temperature($value)` (0 to 2, else `InvalidArgumentException`) is over `$temperature` and `agenticgraph.prism.temperature` (default null: none sent), applies on every path and is in the cache key; it is not checked against the model: some reasoning models accept a temperature only with reasoning off, otherwise the provider rejects the call and it appears as a failed LLM call. `->timeout($seconds, $connectSeconds)` overrides `agenticgraph.api.timeout` / `connect_timeout` for one call (no node layer); each tool step and structured retry is its own HTTP request, and a streamed reply must finish within it. `reasoning()` (level or `budget:`) resolves node, then call, with no config layer; it throws when the provider cannot apply it (the DeepSeek provider, a budget on OpenAI or Ollama, Anthropic with a forced tool choice, an Anthropic level without `agenticgraph.prism.reasoning.budgets.*`, `off` on OpenAI without `agenticgraph.prism.reasoning.off.openai`, whose value is model-specific: `none`, `minimal`; for one node use raw `providerOptions(['reasoning' => ['effort' => ...]])`). `thoughtTokens` is null when the provider does not report it. `explain()` shows each setting with its source, the stream and cache decisions, the cache key and the `context()` text, without calling the model.
- Caching: off by default. Chat replies call `withoutCache()` (rule 4); `withCache($ttlMinutes)` turns it on for one request. Tool and MCP requests never cache.
- Streaming when the run streams: text and chat; `schema()` only with a Prism `Schema` and `prompt()`; tool runs (every step) and MCP. In a multi-step run `run()->text` (and the hooks' `finalText`) is the final step; `steps` (and the hooks' `text`) hold the narration. Decide in the app which to persist. A structured retry after a streamed attempt runs without streaming.
- `protected bool $trackTokens = false` stops `TokensUsed`. `protected array $providerOptions` takes the options your provider needs, for example `['use_tool_calling' => true]` where its structured output requires tool calling (check the Prism docs for your provider and model).
- Structured, tool loop and MCP examples: [references/prism-nodes.md](references/prism-nodes.md).

Docs: `/docs/nodes`, `/docs/api/nodes`

## Task: write prompts

- `{word}` is a placeholder. `{{` and `}}` are literal braces. `{user.name}` is not a path; flatten in PHP.
- A missing variable throws `TemplateException`.
- `PromptTemplate::create($template)->withDefaults([...])->withRequired([...])`, then `format($vars)`.
- `ChatPromptTemplate` keeps role messages. `formatAsString()` is for cache keys and logs, not the request.
- A long prompt goes in `resources/prompts/{dir}/{name}.md` and is loaded with `chat(Prompts::get('dir.name'))`. Front matter: `name` (the dotted path), `version`, optional `description`; never a model or settings (they stay on the node; it throws). Headings `## system`, `## user`, `## assistant`, `## messages: history`; `###` inside a message. The llm span records `prompt.name`, `prompt.version`, `prompt.hash`. Run `php artisan agenticgraph:prompts` in CI. Example: [references/prism-nodes.md](references/prism-nodes.md).

Docs: `/docs/prompts`, `/docs/guide-prompt-files`, `/docs/api/prompts`

## Task: manage state

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

## Task: route between nodes

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
- `$defaultRoute` is taken when `route()` returns an empty route or a node the graph does not have (a warning and `RouteFellBack`). Without one, or for a fan-out naming an unknown node, the run throws `RoutingException`.
- `PrismRouter` asks the model to pick one route from the descriptions. It never fans out. To add rules, override `buildPrompt()`, not `route()`. A versioned routing prompt: pass `prompt: Prompts::get('dir.name')` to the constructor and override `routingVars()` (defaults `routes`, `state`).
- More router shapes (PrismRouter subclass, loop-aware, fan-out): [references/routers.md](references/routers.md).

Docs: `/docs/edges`, `/docs/routing`

### Cyclic graphs

- A loop is an edge or route that points back.
- The exit must be a separate node the router can name. Do not mark the looping node as the exit: after it runs, the graph ends.
- Count iterations in state, or implement `LoopAwareRouterInterface` and read `$context->getNodeVisitCount('draft')` in `routeWithContext()`.
- Without a cap the loop runs until the iteration limit throws, and the run fails.
- `cycle_detection=error` rejects cycles at `build()`. Leave it `silent` for graphs meant to loop.

Docs: `/docs/cyclic`

### Parallel execution

- A router returns an array of node ids to fan out. Then `addJoin(['write', 'seo'], 'merge')` waits for all of them.
- Without `addJoin`, nothing waits for every branch.
- Each branch starts from the same snapshot. Write unique keys per branch. Colliding scalar keys are last-write-wins, with a warning.
- Sync runs branches one after another in this process. Async sends one job per branch on the graph queue.

Docs: `/docs/parallel`

## Task: run a graph

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

## Task: pause for a human

- A gate is a `PrismNode` that overrides `shouldInterrupt()`. It is checked before `execute()`, so the gate pauses on data earlier nodes wrote. `AbstractNode` gates are never asked.
- The run stops with status `INTERRUPTED`, which is not a failure. Override `getInterruptReason()` and `getInterruptPayload()` for the reviewer, and `onResume($state, $humanInput)` to apply the answer. `handleApproval()` writes `approved` and `approval_comments`.
- Same request: `$handle->resume($input)`. Later request: `AgenticGraph::resume($executionId, $input)`, which returns a `ResumeResult` (`->handle`, `->interrupt`). It needs interrupt persistence (on by default) and throws without it.
- `AgenticGraph::interruptDetails($executionId)` returns the pending interrupt and latest state for your UI.
- Gate and controller example: [references/interrupts.md](references/interrupts.md).

Docs: `/docs/interrupts`

## Task: use checkpoints

- The coordinator writes a snapshot after each node (`checkpoint.frequency`) and before a gate. Nodes never call it.
- `checkpoint.enabled` (snapshots) and `interrupts.persistence_enabled` (resume in a later request) are separate settings.
- `$handle->checkpoint()` returns the latest `Checkpoint`. `StateManager::rollback($executionId, $checkpointId)` returns that snapshot's `State`; the graph does not walk backwards.
- A worker restart retries the job. It does not load the latest checkpoint by itself.

Docs: `/docs/checkpoints`

## Task: call MCP tools

```php
use Prism\Prism\Enums\ToolChoice;

$response = $this->llm()
    ->system($systemPrompt)
    ->prompt('{question}')
    ->vars(['question' => $question])
    ->mcp('context7')
    ->toolChoice(ToolChoice::Any)
    ->maxSteps(5)
    ->run();

return $state->set('answer', $response->text)->set('tool_calls', $response->toArray()['tool_calls']);
```

- The server name is a key in `config/relay.php` (`vendor:publish --tag=relay-config`).
- `mcp()` uses one step and Prism's tool choice, like `tools()`. Without `maxSteps` the model cannot call a tool and then answer; pass `ToolChoice::Any` to force a tool call.
- MCP requests never stream and never cache. `TokensUsed` carries `call_type` `mcp`.
- On servers, set `RELAY_NPX_PATH` and `PATH` in the env.

Docs: `/docs/mcp`, `/docs/api/mcp`

## Task: add middleware

- Configured only in `config('agenticgraph.middleware.global')`, never on `GraphBuilder`. The first entry is outermost.
- No interface. A class with `handle(StateContract $state, NodeInterface $node, Closure $next): StateContract`. Not Laravel HTTP middleware.
- The defaults are logging, timing and validation. `ValidationMiddleware` calls `$state->validate()` before and after each node.
- `CachingMiddleware` and `CircuitBreakerMiddleware` are opt-in and apply to every node name. Nodes without `isCacheable()` get cached, including LLM nodes. The circuit breaker is per node name, process-wide.
- Retries loop inside `$next`. Your `handle()` runs once per node, not once per attempt.

Docs: `/docs/guide-middleware`

## Task: listen to lifecycle events

Ordinary Laravel events in `Shahzad\AgenticGraph\Events`. Not stream events, not spans.

- The ones to listen for: `GraphExecutionFailed` (`exception`), `TokensUsed` (`model`, `totalTokens`, `callType`), `InterruptPending` (`interruptId`, `checkpointId`, `payload`).
- Also: `GraphExecutionStarted`, `GraphExecutionCompleted`, `NodeExecutionStarted`, `NodeExecutionCompleted`, `NodeExecutionFailed` (each attempt), `NodeInterrupted`, `NodeResumed`, `CheckpointCreated`, `RouteDecided`, `ToolCallCaptured`, `CycleCompleted`.
- Listeners run in the process that dispatched the event. To queue one, put `ShouldQueue` on the listener; on the event class it does nothing.
- `InterruptPending` needs interrupt persistence. `AGENTICGRAPH_INTERRUPT_BROADCAST` only toggles whether it is dispatched. It is not Echo.
- Do not add `broadcastOn()` to package events. Broadcast from your listener.

Docs: `/docs/guide-events`

## Task: use the CLI

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

## Task: test a graph or a node

- Test node logic by calling `execute()` on the node with a `State`. No graph needed.
- Fake the model: `$llm = Llm::fake(['reply', ['route' => 'billing'], new RuntimeException('timeout')])`. One entry per provider request, in order: text, structured data, a failure, or a Prism response fake (tool runs). Assert with `assertPrompted(fn)`, `assertNotInterpolated($var)`, `assertSettings([...])`, `assertSentCount($n)`, `assertNothingSent()`.
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
