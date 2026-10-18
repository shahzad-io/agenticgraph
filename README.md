# AgenticGraph

AgenticGraph is the first open-source Laravel AI orchestration package, bringing graph-based workflows to the Laravel ecosystem for the millions of PHP developers building real-world AI features. It provides a clean graph builder, PrismPHP-powered nodes, routing, checkpoints, and execution tooling so you can ship agentic systems without jumping to other stacks.

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

- **Wire contract v1.1 (frozen):** [docs/streaming/WIRE-CONTRACT.md](docs/streaming/WIRE-CONTRACT.md): canonical NPM/AgenticPulse type spec
- Streaming guide: [Streaming](https://agenticgraph.dev/docs/streaming)
- API reference: [Streaming API](https://agenticgraph.dev/docs/api/streaming)
- Event catalog: every event type and payload is in the wire contract above
- Deployment: [Stream transports](https://agenticgraph.dev/docs/stream-transports) (broadcast vs SSE and what each needs)
- Troubleshooting: [Stream limitations](https://agenticgraph.dev/docs/stream-limitations)
- Examples: [Stream client getting started](https://agenticgraph.dev/docs/stream-getting-started)

## Tracing

- **Trace wire format `agenticgraph.trace.v1.1`:** [spec/tracing-wire-v1.md](spec/tracing-wire-v1.md): the envelope every JSON exporter sends (pulse, file, log), its segments and merge rules, span types and attribute names, and the OTLP mapping.
- Exporters: `auto`, `pulse`, `log`, `file`, `null`, `otlp` (`AGENTICGRAPH_TRACING_EXPORTER`). `auto` is `null` in unit tests, `pulse` when `AGENTICPULSE_API_KEY` is set, otherwise `log`.

## Installation

Requirements: PHP 8.2+, Laravel 11, 12 or 13.

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

Publish the migrations again and run them. Files you already published keep their names; the new tables and the `zzzz_*` upgrade migrations are added, and each upgrade checks the schema first:

```bash
php artisan vendor:publish --tag=agenticgraph-migrations
php artisan migrate
```

The rest of the breaking changes are in [CHANGELOG.md](CHANGELOG.md).

## Compatibility

The three packages are released together:

| Package | Version | Works with |
|---|---|---|
| `shahzad/agenticgraph` (core) | 0.2.x | PHP 8.2+, Laravel 11, 12 or 13, Prism 0.100 |
| `shahzad/agenticgraph-vector` | 0.1.x | PHP 8.2+, Laravel 11, 12 or 13, Prism 0.100, PostgreSQL with pgvector (0.8+ for iterative scan). Core 0.2.x is optional (tracing) |
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

## Middleware

Configure global node middleware in `config/agenticgraph.php` under `middleware.global` (onion-style around node execution). Built-ins include logging, timing, validation, circuit breaker, and caching.

## Checkpoints & Events

- Checkpoints: `checkpoint.enabled` + `checkpoint.frequency` (every N nodes). No strategy enum.
- Resume: `AgenticGraph::resume($executionId, $humanInput)` or `GraphExecutor::resumeWithInput($checkpointId, ...)`.
- Events: Laravel events (`NodeExecutionStarted`, `CheckpointCreated`, `InterruptPending`, etc.); most implement `ShouldQueue`.

## CLI Commands

- `agenticgraph:list` — list registered graphs
- `agenticgraph:visualize {name} [--output=]` — show/write Mermaid diagram
- `agenticgraph:execute {name} [--state='{}']` — execute synchronously
- `agenticgraph:checkpoint:list [--graph=] [--limit=50]`
- `agenticgraph:checkpoint:resume {id}`
- `agenticgraph:checkpoint:delete {id}`
- `agenticgraph:checkpoint:cleanup [--days=30]`
- `agenticgraph:executions [--graph=] [--status=] [--limit=20]`

## License

MIT
