# AgenticGraph

AgenticGraph is the first open-source Laravel AI orchestration package, bringing graph-based workflows to the Laravel ecosystem for the millions of PHP developers building real-world AI features. It provides a clean graph builder, PrismPHP-powered nodes, routing, checkpoints, and execution tooling so you can ship agentic systems without jumping to other stacks.

**Pre-1.0:** minor versions may contain breaking changes. Read [CHANGELOG.md](CHANGELOG.md) before you upgrade.

## Features

- Graph-based orchestration with nodes, edges, routers and joins
- PrismPHP integration for text, structured outputs and tool-calling
- Sync and async execution, fan-out with queue batches (concurrent with one worker per branch)
- Checkpoints and resume, distributed locks, retries and middleware
- Events for observability and token usage tracking
- Storage drivers: database, Redis, file
- CLI tools to list/execute/visualize graphs and manage checkpoints
- Pest-based test suite and examples

## Documentation

- Full documentation: [agenticgraph.dev/docs](https://agenticgraph.dev/docs/overview)
- Covers configuration, .env settings, execution, routing, storage, middleware, events, checkpoints, and CLI

## Streaming

- **Wire contract v1.1 (frozen):** [WIRE-CONTRACT.md](https://github.com/shahzad-io/agenticgraph/blob/main/docs/streaming/WIRE-CONTRACT.md): the event types and payloads the npm stream clients read
- Streaming guide: [Streaming](https://agenticgraph.dev/docs/streaming)
- API reference: [Streaming API](https://agenticgraph.dev/docs/api/streaming)
- Event catalog: every event type and payload is in the wire contract above
- Deployment: [Stream transports](https://agenticgraph.dev/docs/stream-transports) (broadcast vs SSE and what each needs)
- Upgrading: [Upgrading to 0.3](https://agenticgraph.dev/docs/stream-upgrading-to-0-3) (stream token, private channel, `createStreamTransport()`)
- Troubleshooting: [Stream limitations](https://agenticgraph.dev/docs/stream-limitations)
- Examples: [Stream client getting started](https://agenticgraph.dev/docs/stream-getting-started)

## Tracing

- **Traces:** each run is sent as one or more segments (`agenticgraph.trace.v1.1`): the trace, its spans (graph, node, llm, tool, route, http, db, vector, custom) and its LLM calls. The `file` and `log` exporters write the same JSON, so you can read it. The Pulse exporter's payload is an internal, versioned format between AgenticGraph and AgenticPulse. To send traces to your own backend, use OTLP. See [Tracing](https://agenticgraph.dev/docs/tracing).
- Exporters: `auto`, `pulse`, `log`, `file`, `null`, `otlp` (`AGENTICGRAPH_TRACING_EXPORTER`). `auto` is `null` in unit tests, `pulse` when `AGENTICPULSE_API_KEY` is set, otherwise `log` (your Laravel log channel).
- **What leaves your app:** by default state key names and value types (`AGENTICGRAPH_CAPTURE_STATE=keys`), exception classes and messages (`AGENTICGRAPH_CAPTURE_ERRORS`), node, graph and router names, and ids (`context.ids`, prompt name/version/hash, `cache_key`, `interrupt.reason`). Prompts, responses and state values only when you turn them on. See [What leaves your app](https://agenticgraph.dev/docs/tracing#what-leaves-your-app).

## Installation

Requirements: PHP 8.3+, Laravel 12.61.1+ or 13.12+.

```bash
composer require agenticgraph/agenticgraph:^0.4
```

Publish the config and the migrations, then migrate:

```bash
php artisan vendor:publish --tag=agenticgraph-config
php artisan vendor:publish --tag=agenticgraph-migrations
php artisan migrate
```

| Tag | What it publishes |
|---|---|
| `agenticgraph-config` | `config/agenticgraph.php`, `config/model_pricing.php` and `config/model_pricing_overrides.php` |
| `agenticgraph-migrations` | The migrations for the five tables: executions, checkpoints, interrupts, stream events and batch results |
| `agenticgraph-prompts` (optional) | The packaged prompt files, into `resources/prompts` |

Publishing again skips files that exist; `--force` overwrites `config/agenticgraph.php` and `config/model_pricing.php`. `config/model_pricing_overrides.php` (your hand-set prices) is published once and never overwritten, with or without `--force`.

Prism and Relay are dependencies and work without publishing their configs. Publish `prism-config` or `relay-config` only to change provider settings or MCP servers.

If you run async graphs, schedule `agenticgraph:executions:expire` every minute, next to `agenticgraph:interrupt:expire`, so a run no worker picked up (or whose worker died) ends with a failed stream instead of waiting forever.

Run data is kept until you prune it. Set `AGENTICGRAPH_EXECUTION_RETENTION_DAYS` and schedule `agenticgraph:executions:prune` to delete old completed and failed runs with everything stored for them.

### AI assistants

Run `php artisan boost:update`. It finds the AgenticGraph packages and asks which skills to add; tick `agenticgraph-core`, `agenticgraph-streaming` and `agenticgraph-tracing`, so your coding assistant knows how to build, stream and trace graphs with this package. A first-time Boost setup is `php artisan boost:install`.

### Upgrading from 0.3.x

Core 0.4.0 is a breaking release. Do these steps in order. The full guide is [Upgrading to 0.4](https://agenticgraph.dev/docs/upgrading-to-0-4), and the full list of changes is in [CHANGELOG.md](CHANGELOG.md).

```bash
composer require agenticgraph/agenticgraph:^0.4
```

1. **Published config.** Add `'retention_days' => env('AGENTICGRAPH_EXECUTION_RETENTION_DAYS'),` to the `execution` block, or the environment value has no effect.
2. **Migrations.** Publish them again and run them (0.4.0 adds none; this brings an older install up to date). Then run `php artisan queue:restart`.
3. **Jobs built by hand.** A graph job you construct yourself needs a queued row first (`ExecutionRepository::queued()`), or it does nothing.
4. **Structured replies.** A reply that misses a required key is retried, then throws. Partial `Llm::fake()` structured replies now fail.
5. **Removed.** `ExecutionRepository::cleanup()` is gone; use `agenticgraph:executions:prune`.

Also check: a deleted run now stays deleted, and inside a database transaction the first job is dispatched after the commit.

### Upgrading from 0.2.x

Upgrade once, to 0.4: do these steps and the steps in "Upgrading from 0.3.x" above together. Core 0.3.0 guards the stream routes with a stream token and makes the broadcast channel private. Do these steps in order. The full guide is [Upgrading to 0.3](https://agenticgraph.dev/docs/stream-upgrading-to-0-3), and the full list of changes is in [CHANGELOG.md](CHANGELOG.md).

```bash
composer require agenticgraph/agenticgraph:^0.4
```

1. **Stream URL.** Pass `$handle->stream()->streamUrl` to the browser: it now carries a stream token. If you build the URL yourself, add `token=$handle->streamToken()`. Without it the SSE route returns 403 `stream_token_invalid`. If your middleware signs the URL, ignore `token` in the signature check, or sign after you add it.
2. **Broadcast.** Update `@agenticgraph/stream-client` to 0.2.0. Send `$handle->stream()->toArray()` to the browser and build the transport with `createStreamTransport(descriptor, { echo })` in place of `EchoTransport`. Add `streamChannelAuthorizer()` to your Echo `channelAuthorization`.
3. **Long sessions.** Give the transport a `refreshToken` callback that asks your app for `$handle->streamToken()`.
4. **Published config.** Add the `streaming.token` block and the `private` and `batch_ms` keys under `streaming.broadcast` (see the CHANGELOG), or the environment values have no effect.
5. **Queue.** `broadcast()` frees the web worker only when the queue connection is not `sync`.
6. **The way back.** `AGENTICGRAPH_STREAM_TOKEN_REQUIRED=false` restores the 0.2 SSE route. `AGENTICGRAPH_STREAM_BROADCAST_PRIVATE=false` and `AGENTICGRAPH_STREAM_BROADCAST_BATCH_MS=0` keep `EchoTransport` 0.1 working. The first two open the stream to anyone who has its id.

### Upgrading from 0.1.0

Do these steps in order, then the steps in "Upgrading from 0.2.x" and "Upgrading from 0.3.x" above. The full list of changes is in [CHANGELOG.md](CHANGELOG.md).

1. **Platform.** Use PHP 8.3+ and Laravel 12.61.1+ or 13.12+. Older PHP and Laravel versions are no longer supported. The package requires `prism-php/prism` `^0.102.0`, which sends Anthropic structured output in native mode (Claude Sonnet 4.5 or newer; for an older model set `$providerOptions = ['use_tool_calling' => true]` on the node):

   ```bash
   composer require agenticgraph/agenticgraph:^0.4 prism-php/prism:^0.102 -W
   ```

2. **Migrations.** Publish the migrations again and run them. Files you already published keep their names. The new tables and the `zzzz_*` upgrade migrations are added, and each upgrade checks the schema first:

   ```bash
   php artisan vendor:publish --tag=agenticgraph-migrations
   php artisan migrate
   ```

3. **Cache.** Flush the cache store that `CachingMiddleware` and LLM caching use. Entries written by 0.1.0 cannot be read (`php artisan cache:clear`, or `php artisan cache:clear <store>` for a named store). The LLM cache key and the `PrismRouter` cache key changed too, so each cached reply misses once and is written again. LLM caching is now off by default (`AGENTICGRAPH_PRISM_CACHE=false`): turn it on in config or per request with `->withCache()`. A published `config/agenticgraph.php` keeps the value written in it.
4. **Trace state.** Tracing is new and off by default. When you turn it on, traces carry state key names and value types, not values (`tracing.capture.state` is `keys`). To send values, set `AGENTICGRAPH_CAPTURE_STATE=diff` (or `full`).
5. **Stream client.** Core 0.2.0 sends the stream wire contract v1.1 with the new `stream_start` / `stream_end` events and one `step_start` / `step_finish` pair per step. Use `@agenticgraph/stream-client`, `stream-react` or `stream-vue` 0.1.0 with it (0.1.1 with core 0.2.1, for custom events over SSE and error details).
6. **Token numbers.** Usage follows "usage semantics v2": `prompt_tokens` is the uncached input, cached input is in `cache_read_tokens` / `cache_write_tokens`, and `completion_tokens` includes reasoning. If you store token counts for billing or analytics, expect a step change.
7. **Removed helpers.** `prompt()`, `promptTemplate()`, `chatPrompt()`, `structured()`, `tools()`, `withModel()` and the other `PrismNode` helpers are removed. Every model call goes through `$this->llm()`. See the [before and after table](CHANGELOG.md#breaking).
8. **Model.** There is no default model, and a model needs its provider prefix (`openai:gpt-4o`, not `gpt-4o`). Set `AGENTICGRAPH_PRISM_MODEL` or `$model` on each node; otherwise the first call throws `MissingModelException`.
9. **Routers.** A `PrismRouter` without a default route now throws `RoutingException` when the model gives no usable decision (it returned an empty route). Set a default route to keep the old behavior. Any other router that returns an empty route or a node the graph does not have now takes its `getDefaultRoute()`, or throws `RoutingException` without one (it failed with an undefined array key).
10. **Node timeout.** `getTimeout()` is removed from `NodeInterface` and `AbstractNode`; nothing read it. Remove `parent::getTimeout()` calls. Use `->timeout()` on a model request and `AGENTICGRAPH_EXECUTION_TIMEOUT` / `AGENTICGRAPH_RESUME_TIMEOUT` for queued runs.

## Compatibility

The three packages are released together:

| Package | Version | Works with |
|---|---|---|
| `agenticgraph/agenticgraph` (core) | 0.4.x | PHP 8.3+, Laravel 12.61.1+ or 13.12+, Prism 0.102 |
| `agenticgraph/vector` | 0.1.x | PHP 8.3+, Laravel 12.61.1+ or 13.12+, Prism 0.102, PostgreSQL with pgvector (0.8+ for iterative scan). Core 0.2.x, 0.3.x or 0.4.x is optional (tracing) |
| `@agenticgraph/stream-client`, `@agenticgraph/stream-react`, `@agenticgraph/stream-vue` | 0.2.x | Core 0.3.x or 0.4.x on the server (stream wire contract v1.1; `BroadcastTransport` needs core 0.3.0 or later; `SSETransport` also works with core 0.2.x), Node.js 18+, React 18+ or Vue 3.4+ for the adapters |

## Quick Start

Define a simple graph with two nodes and run it synchronously:

```php
use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\State\State;

class StartNode extends AbstractNode {
    public function execute(StateContract $state): StateContract {
        return $state->set('count', 1);
    }
}

class EndNode extends AbstractNode {
    public function execute(StateContract $state): StateContract {
        return $state->set('done', true);
    }
}

$graph = GraphBuilder::create('sample')
  ->addNode('start', StartNode::class)
  ->addNode('end', EndNode::class)
  ->addEdge('start', 'end')
  ->setEntryPoint('start')
  ->addExitPoint('end')
  ->build();

$result = app(GraphExecutor::class)->execute($graph, new State());
```

## Running Graphs (Facade)

Register graphs in `config/agenticgraph.php`, then use the facade:

```php
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\Execution\ExecutionOptions;
use Shahzad\AgenticGraph\Execution\ExecutionMode;
use Shahzad\AgenticGraph\Execution\StreamingMode;
use Shahzad\AgenticGraph\State\State;

$state = new State(['topic' => 'Laravel queues']);

// Convenience helpers (each delegates to execute())
$handle = AgenticGraph::run('my-graph', $state);           // SYNC + NONE
$handle = AgenticGraph::dispatch('my-graph', $state);      // ASYNC + NONE
$handle = AgenticGraph::stream('my-graph', $state);        // ASYNC + SSE
$handle = AgenticGraph::broadcast('my-graph', $state);     // ASYNC + BROADCAST

// Full control
$handle = AgenticGraph::execute('my-graph', $state, new ExecutionOptions(
    mode: ExecutionMode::ASYNC,
    streaming: StreamingMode::AUTO,
    interrupts: true,                      // default: true
    estimatedDurationSeconds: 120,
    nodeFilter: ['respond'],
    eventTypeFilter: ['text_delta'],
));
```

### ExecutionOptions

| Parameter | Default | Notes |
|-----------|---------|-------|
| `mode` | `SYNC` | `ExecutionMode::SYNC` or `::ASYNC` |
| `streaming` | `NONE` | `AUTO`, `NONE`, `SSE`, `BROADCAST`, `ITERATOR` |
| `interrupts` | **`true`** | Set `false` to skip HITL gates |
| `estimatedDurationSeconds` | `null` | Used by `AUTO` (`>= force_above_seconds` → broadcast, else SSE) |
| `nodeFilter` | `null` | Optional stream node allow-list |
| `eventTypeFilter` | `null` | Optional stream event allow-list |

There is no `timeout` parameter on `ExecutionOptions`, and no node timeout. Queued runs use `AGENTICGRAPH_EXECUTION_TIMEOUT` and `AGENTICGRAPH_RESUME_TIMEOUT`; model calls use `->timeout()` (below).

Resume interrupted executions by execution ID:

```php
$result = AgenticGraph::resume($executionId, ['approved' => true]);
$handle = $result->handle;
```

## Prism Integration

AgenticGraph integrates with PrismPHP to enable LLM-driven nodes and routing.

Example Prism node. Every model call goes through `$this->llm()`, one immutable builder for text, chat, structured output, tools and MCP:

```php
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Contracts\StateContract;

class RespondNode extends PrismNode {
    public function execute(StateContract $state): StateContract {
        $text = $this->llm()->prompt('Respond to {topic}')->vars(['topic' => $state->get('topic', 'unknown')])->text();
        return $state->set('response', $text);
    }
}
```

`->timeout(90, 5)` sets the provider HTTP timeouts for one request (default `AGENTICGRAPH_API_TIMEOUT` 30 and `AGENTICGRAPH_API_CONNECT_TIMEOUT` 10 seconds). It applies to each HTTP request: each tool step and structured retry gets its own, a streamed reply must finish within it, and tool handlers are not bounded. `->structuredRetries(2)` sets how many times one structured request is sent again after a reply that does not decode or fit `as()` (default `AGENTICGRAPH_PRISM_STRUCTURED_RETRIES`, 1). `explain()` shows both with their source.

Example Prism router:

```php
use Shahzad\AgenticGraph\Routers\PrismRouter;

$router = new PrismRouter([
  'technical' => 'Route to technical',
  'billing' => 'Route to billing',
  'general' => 'Route to general',
], defaultRoute: 'general');
```

The routing call sends `agenticgraph.router.max_tokens` (`AGENTICGRAPH_ROUTER_MAX_TOKENS`, default 1024; reasoning tokens count against it) and `agenticgraph.router.reasoning` (`AGENTICGRAPH_ROUTER_REASONING`, default none); a subclass sets `$maxTokens` or `$reasoning` to override both. When it takes the default route instead of a decision, it logs a warning and dispatches `RouteFellBack`; with no default route it throws `RoutingException` instead.

Every router's `getDefaultRoute()` applies when it returns an empty route (`''`, `[]`) or a node the graph does not have: the run takes the default route, logs a warning and dispatches `RouteFellBack` (`empty_route` or `unknown_route`). With no default route, or one that is not a node, it throws `RoutingException`. A fan-out naming an unknown node always throws.

## Middleware

Configure global node middleware in `config/agenticgraph.php` under `middleware.global` (onion-style around node execution). Built-ins include logging, timing, validation, circuit breaker, and caching.

## Checkpoints & Events

- Checkpoints: `checkpoint.enabled` + `checkpoint.frequency` (every N nodes). No strategy enum.
- Resume: `AgenticGraph::resume($executionId, $humanInput)` or `GraphExecutor::resumeWithInput($checkpointId, ...)`.
- Events: Laravel events (`NodeExecutionStarted`, `CheckpointCreated`, `InterruptPending`, etc.); most implement `ShouldQueue`.

## CLI Commands

- `agenticgraph:list` - list registered graphs
- `agenticgraph:visualize {name} [--output=]` - show/write Mermaid diagram
- `agenticgraph:execute {name} [--state='{}']` - execute synchronously
- `agenticgraph:checkpoint:list [--graph=] [--limit=50]`
- `agenticgraph:checkpoint:resume {id}`
- `agenticgraph:checkpoint:delete {id}`
- `agenticgraph:checkpoint:cleanup [--days=30]`
- `agenticgraph:executions [--graph=] [--status=] [--limit=20]`
- `agenticgraph:executions:expire [--limit=500]` - fail async runs no worker is running
- `agenticgraph:executions:prune [--days=] [--limit=500]` - delete old completed and failed runs with all their data

## License

MIT
