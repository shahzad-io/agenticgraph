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

- Full documentation: [documentation.md](documentation.md)
- Covers configuration, .env settings, execution, routing, storage, middleware, events, checkpoints, and CLI

## Installation

Requirements: PHP 8.2+, Laravel 11 or 12.

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

## License

MIT
