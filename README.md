# AgenticGraph

AgenticGraph is a Laravel package for building agentic workflows using a graph-based architecture with first-class PrismPHP integration for AI-powered nodes and routing. It provides execution orchestration, checkpoints, events, middleware, queue-based parallelism, and developer-friendly tooling.

## Features

- Graph-based orchestration with nodes, edges, routers and joins
- PrismPHP integration for text, structured outputs and tool-calling
- Sync and async execution, parallel fan-out with queue batches
- Checkpoints and resume, distributed locks, retries and middleware
- Events for observability and token usage tracking
- Storage drivers: database, Redis, file
- CLI tools to list/execute/visualize graphs and manage checkpoints
- Pest-based test suite and examples

## Installation

```bash
composer require shahzad/agenticgraph
composer require prism-php/prism:^0.98.0
```

Publish configuration and run migrations in your host app:

```bash
php artisan vendor:publish --tag=prism-config
php artisan vendor:publish --provider="Shahzad\AgenticGraph\AgenticGraphServiceProvider"
php artisan migrate
```

## Configuration

- App config file: `config/agenticgraph.php`
- Key highlights:
  - `agenticgraph.execution.*` queue name/connection, timeouts, retries
  - `agenticgraph.lock.*` lock driver, timeout
  - `agenticgraph.prism.*` default model, cache and token tracking
  - `agenticgraph.checkpoint.*` enabled, strategy, ttl
  - `agenticgraph.storage` driver: `database|redis|file`

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

## Prism Integration

AgenticGraph integrates with PrismPHP to enable LLM-driven nodes and routing.

Example Prism node:

```php
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Contracts\StateContract;

class RespondNode extends PrismNode {
    public function execute(StateContract $state): StateContract {
        $text = $this->prompt('Respond to {topic}', ['topic' => $state->get('topic', 'unknown')]);
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

## CLI Commands

- `agenticgraph:list` — list registered graphs
- `agenticgraph:visualize {name} [--output=]` — show/write Mermaid diagram
- `agenticgraph:execute {name} [--state='{}']` — execute synchronously
- `agenticgraph:checkpoint:list --graph=NAME [--limit=50]`
- `agenticgraph:checkpoint:resume {id}`
- `agenticgraph:checkpoint:delete {id}`
- `agenticgraph:checkpoint:cleanup [--days=30]`
- `agenticgraph:executions [--graph=] [--status=] [--limit=20]`

## Storage Drivers

- Database (default): stores checkpoints and execution history tables
- Redis: ephemeral checkpoints (optional)
- File: writes JSON checkpoints to `storage/app/agenticgraph`

## Events

- Graph: `GraphExecutionStarted`, `GraphExecutionCompleted`
- Node: `NodeExecutionStarted`, `NodeExecutionCompleted`, `NodeExecutionTimed`
- Prism: `TokensUsed` for usage tracking

## Middleware

- Logging, Timing, Validation, Caching
- Configure global pipeline via `agenticgraph.middleware.global`

## Testing

- Pest-based test suite included
- Prism fakes used to avoid external calls

Run tests:

```bash
./vendor/bin/pest
```

## License

MIT
