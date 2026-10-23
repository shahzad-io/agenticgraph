# AgenticGraph

AgenticGraph is the first open-source Laravel AI orchestration package, bringing graph-based workflows to the Laravel ecosystem for the millions of PHP developers building real-world AI features. It provides a clean graph builder, PrismPHP-powered nodes, routing, checkpoints, and execution tooling so you can ship agentic systems without jumping to other stacks.

**Pre-1.0:** minor versions may contain breaking changes. Read [CHANGELOG.md](CHANGELOG.md) before you upgrade.

## Features

- Graph-based orchestration with nodes, edges, routers and joins
- PrismPHP integration for text, structured outputs and tool-calling
- Sync and async execution, parallel fan-out with queue batches
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
- Troubleshooting: [Stream limitations](https://agenticgraph.dev/docs/stream-limitations)
- Examples: [Stream client getting started](https://agenticgraph.dev/docs/stream-getting-started)

## Tracing

- **Traces:** each run is sent as one or more segments (`agenticgraph.trace.v1.1`): the trace, its spans (graph, node, llm, tool, route, http, db, vector, custom) and its LLM calls. The `file` and `log` exporters write the same JSON, so you can read it. The Pulse exporter's payload is an internal, versioned format between AgenticGraph and AgenticPulse. To send traces to your own backend, use OTLP. See [Tracing](https://agenticgraph.dev/docs/tracing).
- Exporters: `auto`, `pulse`, `log`, `file`, `null`, `otlp` (`AGENTICGRAPH_TRACING_EXPORTER`). `auto` is `null` in unit tests, `pulse` when `AGENTICPULSE_API_KEY` is set, otherwise `log` (your Laravel log channel).
- **What leaves your app:** by default state key names and value types (`AGENTICGRAPH_CAPTURE_STATE=keys`), exception classes and messages (`AGENTICGRAPH_CAPTURE_ERRORS`), node, graph and router names, and ids (`context.ids`, prompt name/version/hash, `cache_key`, `interrupt.reason`). Prompts, responses and state values only when you turn them on. See [What leaves your app](https://agenticgraph.dev/docs/tracing#what-leaves-your-app).

## Installation

Requirements: PHP 8.3+, Laravel 12.61.1+ or 13.12+.

```bash
composer require shahzad/agenticgraph
```

Publish configuration and run migrations in your host app:

```bash
php artisan vendor:publish --tag=prism-config
php artisan vendor:publish --tag=relay-config
php artisan vendor:publish --provider="Shahzad\AgenticGraph\AgenticGraphServiceProvider"
php artisan migrate
```

### Upgrading from 0.1.0

Do these steps in order. The full list of changes is in [CHANGELOG.md](CHANGELOG.md).

1. **Platform.** Use PHP 8.3+ and Laravel 12.61.1+ or 13.12+. Older PHP and Laravel versions are no longer supported. The package requires `prism-php/prism` `^0.102.0`:

   ```bash
   composer require shahzad/agenticgraph:^0.2 prism-php/prism:^0.102 -W
   ```

2. **Migrations.** Publish the migrations again and run them. Files you already published keep their names. The new tables and the `zzzz_*` upgrade migrations are added, and each upgrade checks the schema first:

   ```bash
   php artisan vendor:publish --tag=agenticgraph-migrations
   php artisan migrate
   ```

3. **Cache.** Flush the cache store that `CachingMiddleware` and LLM caching use. Entries written by 0.1.0 cannot be read (`php artisan cache:clear`, or `php artisan cache:clear <store>` for a named store). The LLM cache key and the `PrismRouter` cache key changed too, so each cached reply misses once and is written again.
4. **Trace state.** The default `tracing.capture.state` is now `keys`: traces carry state key names and value types, not values. To keep sending values, set `AGENTICGRAPH_CAPTURE_STATE=diff` (or `full`). A published `config/agenticgraph.php` keeps the value written in it.
5. **Stream client.** Core 0.2.0 sends the stream wire contract v1.1 with the new `stream_start` / `stream_end` events and one `step_start` / `step_finish` pair per step. Use `@agenticgraph/stream-client`, `stream-react` or `stream-vue` 0.1.0 with it.
6. **Token numbers.** Usage follows "usage semantics v2": `prompt_tokens` is the uncached input, cached input is in `cache_read_tokens` / `cache_write_tokens`, and `completion_tokens` includes reasoning. If you store token counts for billing or analytics, expect a step change.
7. **Removed helpers.** `prompt()`, `promptTemplate()`, `chatPrompt()`, `promptMessages()`, `structured()`, `tools()`, `agent()`, `withMcp()` and the other `PrismNode` helpers are removed. Every model call goes through `$this->llm()`. See the [before and after table](CHANGELOG.md#breaking-one-llm-request-builder-this-llm).

## Compatibility

The three packages are released together:

| Package | Version | Works with |
|---|---|---|
| `shahzad/agenticgraph` (core) | 0.2.x | PHP 8.3+, Laravel 12.61.1+ or 13.12+, Prism 0.102 |
| `shahzad/agenticgraph-vector` | 0.1.x | PHP 8.3+, Laravel 12.61.1+ or 13.12+, Prism 0.102, PostgreSQL with pgvector (0.8+ for iterative scan). Core 0.2.x is optional (tracing) |
| `@agenticgraph/stream-client`, `@agenticgraph/stream-react`, `@agenticgraph/stream-vue` | 0.1.x | Core 0.2.x on the server (stream wire contract v1.1), Node.js 18+, React 18+ or Vue 3.4+ for the adapters |

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

There is no `timeout` parameter on `ExecutionOptions`.

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

## License

MIT
