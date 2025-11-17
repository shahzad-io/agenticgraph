# AgenticGraph - Complete Developer Documentation

> **Version**: 1.1.0  
> **Laravel**: 10.x, 11.x, 12.x  
> **PHP**: 8.2+  
> **License**: MIT

## 📚 Table of Contents

1. [Overview](#overview)
2. [Installation](#installation)
3. [Core Concepts](#core-concepts)
4. [Quick Start](#quick-start)
5. [Building Graphs](#building-graphs)
6. [Nodes](#nodes)
7. [State Management](#state-management)
8. [Routers & Conditional Logic](#routers--conditional-logic)
9. [Execution Modes](#execution-modes)
10. [PrismPHP Integration](#prismphp-integration)
11. [Checkpoints & Resume](#checkpoints--resume)
12. [Events & Observability](#events--observability)
13. [Middleware](#middleware)
14. [CLI Commands](#cli-commands)
15. [Configuration](#configuration)
16. [Testing](#testing)
17. [Best Practices](#best-practices)
18. [Troubleshooting](#troubleshooting)
19. [API Reference](#api-reference)
20. [Examples](#examples)

---

## Overview

**AgenticGraph** is a Laravel package inspired by LangGraph that provides a powerful graph-based architecture for building agentic workflows. It combines native Laravel patterns with first-class PrismPHP integration to enable complex, stateful AI agents and multi-step workflows.

### Key Features

✅ **Graph-Based Orchestration** - Define workflows as directed graphs with nodes, edges, and conditional routing  
✅ **PrismPHP Integration** - Built-in support for LLM-powered nodes and intelligent routing  
✅ **Flexible Execution** - Synchronous, asynchronous, parallel, and streaming execution modes  
✅ **Checkpoints & Resume** - Save and restore execution state at any point  
✅ **Distributed Locks** - Safe concurrent execution with Redis/database locks  
✅ **Event System** - Comprehensive observability with Laravel events  
✅ **Middleware Pipeline** - Logging, timing, validation, caching, and circuit breaker  
✅ **Multiple Storage Backends** - Database, Redis, or file-based checkpoint storage  
✅ **CLI Tools** - Artisan commands for graph management and debugging  
✅ **Production Ready** - Battle-tested with comprehensive error handling and performance optimizations

### Use Cases

- 🤖 **AI Agents** - Build complex conversational agents with memory and tool use
- 📊 **Data Pipelines** - Orchestrate multi-step data processing workflows
- 🔄 **Business Processes** - Model approval workflows, order processing, etc.
- 🧪 **A/B Testing** - Route users through different experience paths
- 🎯 **Decision Trees** - Implement complex decision-making logic
- 📧 **Email Campaigns** - Multi-step drip campaigns with conditional logic


## Installation

### Requirements

- PHP 8.2 or higher
- Laravel 10.x, 11.x, or 12.x
- Database (MySQL 8.0+, PostgreSQL 13+, or SQLite 3.35+)
- Redis 6+ (optional, for caching and locks)

### Step 1: Install via Composer

```bash
composer require shahzad/agenticgraph
```

### Step 2: No need to Install PrismPHP (for AI features)

this already comes inside agenticgraph package

```

### Step 3: Publish Configuration

```bash
# Publish AgenticGraph config and migrations
php artisan vendor:publish --provider="Shahzad\AgenticGraph\AgenticGraphServiceProvider"

# Publish Prism config (if using AI features)
php artisan vendor:publish --tag=prism-config
```

### Step 4: Run Migrations

```bash
php artisan migrate
```

This creates two tables:
- `agenticgraph_checkpoints` - Stores execution checkpoints
- `agenticgraph_executions` - Tracks execution history

### Step 5: Configure Environment

Add to your `.env` file:

```env
# Storage driver: database, redis, or file
AGENTICGRAPH_STORAGE=database

# Queue configuration
AGENTICGRAPH_QUEUE_CONNECTION=redis
AGENTICGRAPH_QUEUE_NAME=agenticgraph

# Prism configuration (if using AI features)
AGENTICGRAPH_PRISM_MODEL=anthropic:claude-sonnet-4
ANTHROPIC_API_KEY=your-api-key-here
```

### Verification

Test your installation:

```bash
php artisan agenticgraph:list
```

You should see an empty list of registered graphs.

---

## Core Concepts

### Graph

A **Graph** is a directed graph representing your workflow. It consists of:
- **Nodes**: Units of work that transform state
- **Edges**: Connections between nodes
- **Entry Point**: Where execution begins
- **Exit Points**: Where execution can end

```
┌─────────┐     ┌─────────┐     ┌─────────┐
│  Start  │────▶│ Process │────▶│   End   │
└─────────┘     └─────────┘     └─────────┘
```

### Node

A **Node** is a unit of work that:
- Receives the current state
- Performs some operation
- Returns a new state (immutable)

### State

**State** is an immutable data container that flows through the graph:
- Passed from node to node
- Each node returns a new state
- Supports dot notation for nested access
- Can be validated with Laravel validation rules

### Edge

An **Edge** connects two nodes:
- **Simple Edge**: Direct connection (A → B)
- **Conditional Edge**: Uses a router to decide next node
- **Parallel Edge**: Fans out to multiple nodes simultaneously

### Router

A **Router** implements conditional logic to determine the next node:
- Evaluates current state
- Returns the name of the next node(s)
- Can use LLMs for intelligent routing (PrismRouter)


## Quick Start

Let's build a simple customer support workflow:

### Step 1: Create Nodes

```php
<?php

namespace App\Nodes;

use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;

class GreetCustomerNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $customerName = $state->get('customer_name', 'Customer');
        $greeting = "Hello {$customerName}! How can I help you today?";
        
        return $state->set('greeting', $greeting);
    }
}

class ClassifyIntentNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $message = $state->get('message', '');
        
        // Simple keyword-based classification
        if (str_contains(strtolower($message), 'refund')) {
            $intent = 'billing';
        } elseif (str_contains(strtolower($message), 'bug')) {
            $intent = 'technical';
        } else {
            $intent = 'general';
        }
        
        return $state->set('intent', $intent);
    }
}

class RespondNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $intent = $state->get('intent');
        
        $responses = [
            'billing' => 'I can help you with billing issues.',
            'technical' => 'Let me connect you with technical support.',
            'general' => 'I\'m here to help with general inquiries.',
        ];
        
        $response = $responses[$intent] ?? $responses['general'];
        
        return $state->set('response', $response);
    }
}
```

### Step 2: Build the Graph

```php
<?php

namespace App\Graphs;

use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Graph\Graph;
use App\Nodes\GreetCustomerNode;
use App\Nodes\ClassifyIntentNode;
use App\Nodes\RespondNode;

class CustomerSupportGraph
{
    public static function build(): Graph
    {
        return GraphBuilder::create('customer-support')
            ->description('Customer support workflow with intent classification')
            
            // Add nodes
            ->addNode('greet', GreetCustomerNode::class)
            ->addNode('classify', ClassifyIntentNode::class)
            ->addNode('respond', RespondNode::class)
            
            // Connect nodes
            ->addEdge('greet', 'classify')
            ->addEdge('classify', 'respond')
            
            // Define entry and exit points
            ->setEntryPoint('greet')
            ->addExitPoint('respond')
            
            // Add metadata
            ->withMetadata('version', '1.0')
            ->withMetadata('author', 'support-team')
            
            ->build();
    }
}
```

### Step 3: Register the Graph

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Shahzad\AgenticGraph\Graph\GraphRegistry;
use App\Graphs\CustomerSupportGraph;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register graph on application boot
        GraphRegistry::register(
            'customer-support',
            CustomerSupportGraph::build()
        );
    }
}
```

### Step 4: Execute the Graph

```php
<?php

use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\State\State;
use Shahzad\AgenticGraph\Graph\GraphRegistry;

// Get the registered graph
$graph = GraphRegistry::get('customer-support');

// Create initial state
$initialState = new State([
    'customer_name' => 'John Doe',
    'message' => 'I need a refund for my order',
]);

// Execute synchronously
$result = AgenticGraph::execute($graph, $initialState);

// Access results
echo $result->get('greeting');  // "Hello John Doe! How can I help you today?"
echo $result->get('intent');    // "billing"
echo $result->get('response');  // "I can help you with billing issues."
```

### Step 5: Execute via CLI

```bash
php artisan agenticgraph:execute customer-support --state='{"customer_name":"Jane","message":"I found a bug"}'
```


## Nodes

### Creating Custom Nodes

Extend `AbstractNode` and implement the `execute()` method:

```php
<?php

namespace App\Nodes;

use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;

class MyCustomNode extends AbstractNode
{
    // Optional: Override node name (defaults to class name)
    protected string $name = 'My Custom Node';
    
    public function execute(StateContract $state): StateContract
    {
        // Get data from state
        $input = $state->get('input');
        
        // Perform your logic
        $output = strtoupper($input);
        
        // Return new state (immutable)
        return $state->set('output', $output);
    }
    
    // Optional: Configure retries
    public function getRetryAttempts(): int
    {
        return 5; // Default is 3
    }
    
    public function getRetryDelay(): int
    {
        return 2000; // Milliseconds, default is 1000
    }
    
    // Optional: Configure timeout
    public function getTimeout(): ?int
    {
        return 60; // Seconds, default is 300
    }
    
    // Optional: Lifecycle hooks
    public function onBefore(StateContract $state): void
    {
        \Log::info('Starting node execution', ['node' => $this->getName()]);
    }
    
    public function onAfter(StateContract $state, StateContract $result): void
    {
        \Log::info('Completed node execution', ['node' => $this->getName()]);
    }
    
    public function onError(\Throwable $exception, StateContract $state): void
    {
        \Log::error('Node execution failed', [
            'node' => $this->getName(),
            'error' => $exception->getMessage(),
        ]);
    }
}
```

### Node with Dependencies

Inject Laravel services via constructor:

```php
<?php

namespace App\Nodes;

use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;

class ProcessPaymentNode extends AbstractNode
{
    public function __construct(
        private PaymentService $paymentService
    ) {}
    
    public function execute(StateContract $state): StateContract
    {
        $amount = $state->get('amount');
        $customerId = $state->get('customer_id');
        
        DB::transaction(function () use ($amount, $customerId, &$result) {
            $result = $this->paymentService->charge($customerId, $amount);
        });
        
        return $state->set('payment_result', $result);
    }
}
```

### Conditional Node Execution

```php
class ConditionalNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        if ($state->get('skip_processing')) {
            // Skip processing
            return $state;
        }
        
        // Perform processing
        $result = $this->process($state->get('data'));
        
        return $state->set('processed', $result);
    }
}
```

### Node Tags

Add tags for categorization and filtering:

```php
class AnalyticsNode extends AbstractNode
{
    public function getTags(): array
    {
        return ['analytics', 'reporting', 'non-critical'];
    }
    
    public function execute(StateContract $state): StateContract
    {
        // Analytics logic
        return $state;
    }
}
```


## State Management

### Creating State

```php
use Shahzad\AgenticGraph\State\State;

// Simple state
$state = new State([
    'user_id' => 123,
    'message' => 'Hello',
]);

// Nested state
$state = new State([
    'user' => [
        'id' => 123,
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ],
    'order' => [
        'id' => 456,
        'total' => 99.99,
    ],
]);
```

### Accessing State

```php
// Simple access
$userId = $state->get('user_id');

// Dot notation for nested access
$userName = $state->get('user.name');
$orderTotal = $state->get('order.total');

// With default value
$status = $state->get('status', 'pending');

// Check if key exists
if ($state->has('user.email')) {
    // ...
}

// Get all data
$allData = $state->all();
```

### Modifying State (Immutable)

```php
// Set a value (returns new state)
$newState = $state->set('status', 'completed');

// Set nested value
$newState = $state->set('user.verified', true);

// Remove a key
$newState = $state->forget('temporary_data');

// Original state is unchanged
echo $state->get('status');     // null
echo $newState->get('status');  // 'completed'
```

### Custom State Classes

Create domain-specific state classes with validation:

```php
<?php

namespace App\State;

use Shahzad\AgenticGraph\State\State;

class OrderState extends State
{
    protected function rules(): array
    {
        return [
            'order_id' => 'required|integer',
            'customer_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'total' => 'required|numeric|min:0',
            'status' => 'required|in:pending,processing,completed,cancelled',
        ];
    }
}

// Usage
$orderState = new OrderState([
    'order_id' => 123,
    'customer_id' => 456,
    'items' => [
        ['product_id' => 1, 'quantity' => 2],
        ['product_id' => 2, 'quantity' => 1],
    ],
    'total' => 149.99,
    'status' => 'pending',
]);

// Validation happens automatically
try {
    $orderState->validate();
} catch (StateValidationException $e) {
    echo $e->getMessage(); // Validation errors
}
```

### Merging States

Used in parallel execution to combine results:

```php
$state1 = new State(['a' => 1, 'b' => 2]);
$state2 = new State(['b' => 3, 'c' => 4]);

$merged = $state1->merge($state2);
// Result: ['a' => 1, 'b' => 3, 'c' => 4]

// Nested arrays are merged properly
$state1 = new State(['user' => ['name' => 'John', 'age' => 30]]);
$state2 = new State(['user' => ['email' => 'john@example.com']]);

$merged = $state1->merge($state2);
// Result: ['user' => ['name' => 'John', 'age' => 30, 'email' => 'john@example.com']]
```

### State Metadata

Store execution metadata separately from business data:

```php
$state = new State(
    data: ['user_id' => 123],
    metadata: ['execution_time' => microtime(true), 'version' => '1.0']
);

$metadata = $state->getMetadata();
```

### State Serialization

```php
// To array
$array = $state->toArray();

// From array
$state = State::fromArray($array);

// To JSON
$json = json_encode($state); // Implements JsonSerializable
```


## Routers & Conditional Logic

### Creating Custom Routers

Implement `RouterInterface`:

```php
<?php

namespace App\Routers;

use Shahzad\AgenticGraph\Routers\AbstractRouter;
use Shahzad\AgenticGraph\Contracts\StateContract;

class ScoreRouter extends AbstractRouter
{
    public function __construct()
    {
        $this->routes = ['high_score', 'medium_score', 'low_score'];
        $this->defaultRoute = 'low_score';
    }
    
    public function route(StateContract $state): string|array
    {
        $score = $state->get('score', 0);
        
        if ($score >= 80) {
            return 'high_score';
        } elseif ($score >= 50) {
            return 'medium_score';
        } else {
            return 'low_score';
        }
    }
}
```

### Parallel Routing

Return an array to execute multiple nodes in parallel:

```php
class ParallelProcessingRouter extends AbstractRouter
{
    public function route(StateContract $state): string|array
    {
        // Execute all three nodes simultaneously
        return ['process_images', 'process_text', 'process_metadata'];
    }
}
```

### Closure-Based Routing

For simple logic, use closures:

```php
$graph->addConditionalEdge('check_age', function(StateContract $state) {
    $age = $state->get('age');
    return $age >= 18 ? 'adult_content' : 'general_content';
});
```

### Router with External Data

```php
class DatabaseRouter extends AbstractRouter
{
    public function __construct(
        private UserRepository $userRepo
    ) {
        $this->routes = ['premium', 'standard', 'trial'];
        $this->defaultRoute = 'trial';
    }
    
    public function route(StateContract $state): string|array
    {
        $userId = $state->get('user_id');
        $user = $this->userRepo->find($userId);
        
        return match($user->subscription_tier) {
            'premium' => 'premium',
            'standard' => 'standard',
            default => 'trial',
        };
    }
}
```

---

## Execution Modes

### Synchronous Execution

Execute and wait for completion:

```php
use Shahzad\AgenticGraph\Facades\AgenticGraph;

$result = AgenticGraph::execute($graph, $initialState);

// Result is the final state
echo $result->get('output');
```

### Asynchronous Execution

Dispatch to queue and return immediately:

```php
$executionId = AgenticGraph::executeAsync($graph, $initialState);

// Returns execution ID immediately
echo "Execution ID: {$executionId}";

// Check status later
$execution = DB::table('agenticgraph_executions')
    ->where('id', $executionId)
    ->first();

echo $execution->status; // queued, running, completed, failed
```

### Execute Until Condition

Execute until a condition is met, then pause:

```php
$result = AgenticGraph::executeUntil($graph, $initialState, function($state) {
    // Pause when human review is needed
    return $state->get('needs_human_review') === true;
});

// Returns execution ID and checkpoint ID
$executionId = $result['executionId'];
$checkpointId = $result['checkpointId'];

// Resume later
$finalState = AgenticGraph::resume($checkpointId);
```

### Parallel Execution

Automatically handled when router returns array:

```php
// This router triggers parallel execution
class ParallelRouter implements RouterInterface
{
    public function route(StateContract $state): string|array
    {
        return ['node_a', 'node_b', 'node_c']; // All execute in parallel
    }
}

// States from parallel nodes are automatically merged
$result = AgenticGraph::execute($graph, $initialState);
```

### Streaming Execution

Get updates as nodes complete:

```php
// Note: This is a planned feature, not yet implemented
foreach (AgenticGraph::stream($graph, $initialState) as $step) {
    echo "Completed: {$step->nodeName}\n";
    echo "State: " . json_encode($step->state->toArray()) . "\n";
}
```


## PrismPHP Integration

### PrismNode Basics

Extend `PrismNode` for LLM-powered nodes:

```php
<?php

namespace App\Nodes;

use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Contracts\StateContract;

class SummarizeNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $text = $state->get('article_text');
        
        // Simple text generation
        $summary = $this->prompt(
            'Summarize the following article in 2-3 sentences: {text}',
            ['text' => $text]
        );
        
        return $state->set('summary', $summary);
    }
}
```

### Structured Output

Get structured data from LLMs:

```php
class ExtractInfoNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $text = $state->get('email_body');
        
        $schema = [
            'sender_name' => 'string',
            'sender_email' => 'string',
            'subject' => 'string',
            'urgency' => 'low|medium|high',
            'action_required' => 'boolean',
        ];
        
        $extracted = $this->structured(
            "Extract information from this email: {$text}",
            $schema
        );
        
        return $state->set('extracted_info', $extracted);
    }
}
```

### Tool Calling

Enable LLMs to call functions:

```php
use EchoLabs\Prism\Tool;

class WeatherNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $location = $state->get('location');
        
        $tools = [
            Tool::as('get_weather')
                ->for('Get current weather for a location')
                ->withParameter('location', 'The city name')
                ->using(fn($location) => $this->getWeather($location)),
        ];
        
        $toolCalls = $this->tools(
            "What's the weather in {$location}?",
            $tools
        );
        
        return $state->set('weather_info', $toolCalls);
    }
    
    private function getWeather(string $location): array
    {
        // Call weather API
        return ['temp' => 72, 'condition' => 'sunny'];
    }
}
```

### Custom Model Configuration

```php
class GPT4Node extends PrismNode
{
    protected ?string $model = 'openai:gpt-4';
    
    public function execute(StateContract $state): StateContract
    {
        // Uses GPT-4 instead of default model
        $response = $this->prompt('Analyze: {text}', [
            'text' => $state->get('input')
        ]);
        
        return $state->set('analysis', $response);
    }
}
```

### Caching LLM Responses

```php
class CachedNode extends PrismNode
{
    protected ?int $cacheTtl = 120; // Cache for 2 hours
    
    public function execute(StateContract $state): StateContract
    {
        // Response is automatically cached
        $response = $this->prompt('Generate report for {date}', [
            'date' => $state->get('date')
        ]);
        
        return $state->set('report', $response);
    }
}

// Or disable caching
class NoCacheNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $this->withoutCache();
        
        $response = $this->prompt('Real-time data: {query}', [
            'query' => $state->get('query')
        ]);
        
        return $state->set('result', $response);
    }
}
```

### PrismRouter

Use LLMs for intelligent routing:

```php
use Shahzad\AgenticGraph\Routers\PrismRouter;

$router = new PrismRouter([
    'technical' => 'Route to technical support for bugs, errors, and technical issues',
    'billing' => 'Route to billing for payment, refund, and subscription questions',
    'general' => 'Route to general support for other inquiries',
], defaultRoute: 'general');

$graph->addConditionalEdge('classify', $router);
```

The LLM will analyze the state and choose the most appropriate route based on the descriptions.

### Token Tracking

Track LLM usage:

```php
use Shahzad\AgenticGraph\Events\TokensUsed;

Event::listen(TokensUsed::class, function (TokensUsed $event) {
    Log::info('Tokens used', [
        'node' => $event->nodeName,
        'prompt_tokens' => $event->promptTokens,
        'completion_tokens' => $event->completionTokens,
        'total_tokens' => $event->totalTokens,
    ]);
    
    // Store in database for billing
    DB::table('token_usage')->insert([
        'node' => $event->nodeName,
        'tokens' => $event->totalTokens,
        'created_at' => now(),
    ]);
});
```


## Checkpoints & Resume

### Automatic Checkpoints

Checkpoints are automatically created based on your configuration:

```php
// config/agenticgraph.php
'checkpoint' => [
    'enabled' => true,
    'strategy' => 'every_node', // 'every_node', 'conditional', 'manual', 'error'
    'ttl' => 7, // Days to keep checkpoints
],
```

Strategies:
- **every_node**: Save after each node execution (default)
- **conditional**: Save only at decision points (routers, parallel splits)
- **manual**: Save only when explicitly called
- **error**: Save only on failures

### Manual Checkpoints

```php
use Shahzad\AgenticGraph\Execution\CheckpointManager;
use Shahzad\AgenticGraph\Execution\Checkpoint;

class ImportantNode extends AbstractNode
{
    public function __construct(
        private CheckpointManager $checkpointManager
    ) {}
    
    public function execute(StateContract $state): StateContract
    {
        // Do important work
        $result = $this->processData($state->get('data'));
        
        // Manually create checkpoint
        $checkpoint = new Checkpoint();
        $checkpoint->graphName = 'my-graph';
        $checkpoint->executionId = $state->get('execution_id');
        $checkpoint->state = $state->set('result', $result);
        $checkpoint->currentNode = 'important_node';
        $checkpoint->createdAt = new \DateTimeImmutable();
        
        $this->checkpointManager->save($checkpoint);
        
        return $state->set('result', $result);
    }
}
```

### Resuming Execution

```php
use Shahzad\AgenticGraph\Facades\AgenticGraph;

// Resume from checkpoint ID
$finalState = AgenticGraph::resume($checkpointId);

// Or via CLI
php artisan agenticgraph:checkpoint:resume {checkpoint-id}
```

### Listing Checkpoints

```php
use Shahzad\AgenticGraph\Storage\DatabaseStorage;

$storage = new DatabaseStorage();

// List checkpoints for a graph
$checkpoints = $storage->list('customer-support', limit: 50);

foreach ($checkpoints as $checkpoint) {
    echo "ID: {$checkpoint['id']}\n";
    echo "Node: {$checkpoint['current_node']}\n";
    echo "Created: {$checkpoint['created_at']}\n";
}

// Or via CLI
php artisan agenticgraph:checkpoint:list --graph=customer-support --limit=50
```

### Checkpoint Cleanup

```php
use Shahzad\AgenticGraph\Storage\DatabaseStorage;

$storage = new DatabaseStorage();

// Delete checkpoints older than 30 days
$deleted = $storage->cleanup(olderThanDays: 30);

echo "Deleted {$deleted} old checkpoints\n";

// Or via CLI
php artisan agenticgraph:checkpoint:cleanup --days=30
```

### Storage Backends

#### Database Storage (Default)

```php
// config/agenticgraph.php
'storage' => 'database',
```

Stores checkpoints in `agenticgraph_checkpoints` table.

#### Redis Storage

```php
// config/agenticgraph.php
'storage' => 'redis',

'checkpoint' => [
    'ttl' => 1, // Redis checkpoints are ephemeral
],
```

Fast but data is lost if Redis restarts.

#### File Storage

```php
// config/agenticgraph.php
'storage' => 'file',
```

Stores checkpoints as JSON files in `storage/app/agenticgraph/`.

### Human-in-the-Loop Pattern

```php
// Execute until human review needed
$result = AgenticGraph::executeUntil($graph, $initialState, function($state) {
    return $state->get('confidence') < 0.8;
});

$checkpointId = $result['checkpointId'];

// Show to human for review
$checkpoint = $storage->load($checkpointId);
$reviewData = $checkpoint->state->toArray();

// After human approval, update state and resume
$checkpoint->state = $checkpoint->state->set('approved', true);
$storage->save($checkpoint);

$finalState = AgenticGraph::resume($checkpointId);
```


## Events & Observability

### Available Events

AgenticGraph dispatches events at key points:

```php
use Shahzad\AgenticGraph\Events\GraphExecutionStarted;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionStarted;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionTimed;
use Shahzad\AgenticGraph\Events\TokensUsed;
```

### Listening to Events

#### In EventServiceProvider

```php
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use App\Listeners\LogGraphExecution;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        GraphExecutionCompleted::class => [
            LogGraphExecution::class,
        ],
    ];
}
```

#### Using Closures

```php
use Illuminate\Support\Facades\Event;
use Shahzad\AgenticGraph\Events\NodeExecutionCompleted;

Event::listen(NodeExecutionCompleted::class, function (NodeExecutionCompleted $event) {
    Log::info('Node completed', [
        'execution_id' => $event->executionId,
        'node' => $event->nodeName,
        'duration' => $event->duration,
    ]);
});
```

### Event Data

#### GraphExecutionStarted

```php
$event->executionId;    // string
$event->graphName;      // string
$event->initialState;   // StateContract
$event->timestamp;      // DateTimeImmutable
```

#### GraphExecutionCompleted

```php
$event->executionId;    // string
$event->graphName;      // string
$event->finalState;     // StateContract
$event->timestamp;      // DateTimeImmutable
$event->duration;       // float (seconds)
$event->metadata;       // array
```

#### NodeExecutionStarted

```php
$event->executionId;    // string
$event->nodeName;       // string
$event->inputState;     // StateContract
$event->timestamp;      // DateTimeImmutable
```

#### NodeExecutionCompleted

```php
$event->executionId;    // string
$event->nodeName;       // string
$event->inputState;     // StateContract
$event->outputState;    // StateContract
$event->duration;       // float (seconds)
$event->metadata;       // array
```

#### NodeExecutionTimed

```php
$event->nodeName;       // string
$event->duration;       // float (seconds)
```

#### TokensUsed

```php
$event->nodeName;           // string
$event->promptTokens;       // int
$event->completionTokens;   // int
$event->totalTokens;        // int
```

### Monitoring Example

```php
use Shahzad\AgenticGraph\Events\GraphExecutionCompleted;
use Shahzad\AgenticGraph\Events\NodeExecutionTimed;
use Shahzad\AgenticGraph\Events\TokensUsed;

// Track execution metrics
Event::listen(GraphExecutionCompleted::class, function ($event) {
    Metrics::timing('graph.execution.duration', $event->duration, [
        'graph' => $event->graphName,
    ]);
});

// Track slow nodes
Event::listen(NodeExecutionTimed::class, function ($event) {
    if ($event->duration > 5.0) {
        Log::warning('Slow node detected', [
            'node' => $event->nodeName,
            'duration' => $event->duration,
        ]);
    }
});

// Track LLM costs
Event::listen(TokensUsed::class, function ($event) {
    $cost = $event->totalTokens * 0.00001; // Example pricing
    
    DB::table('llm_usage')->insert([
        'node' => $event->nodeName,
        'tokens' => $event->totalTokens,
        'estimated_cost' => $cost,
        'created_at' => now(),
    ]);
});
```

### Disabling Events

```php
// config/agenticgraph.php
'events' => [
    'enabled' => false, // Disable all events
],
```


## Middleware

### Built-in Middleware

AgenticGraph includes several middleware classes:

- **LoggingMiddleware** - Logs node execution
- **TimingMiddleware** - Tracks execution time
- **ValidationMiddleware** - Validates state before/after nodes
- **CachingMiddleware** - Caches node results
- **CircuitBreakerMiddleware** - Prevents cascading failures

### Global Middleware

Configure in `config/agenticgraph.php`:

```php
'middleware' => [
    'global' => [
        \Shahzad\AgenticGraph\Middleware\LoggingMiddleware::class,
        \Shahzad\AgenticGraph\Middleware\TimingMiddleware::class,
        \Shahzad\AgenticGraph\Middleware\ValidationMiddleware::class,
    ],
],
```

### Custom Middleware

Create your own middleware:

```php
<?php

namespace App\Middleware;

use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\StateContract;

class MetricsMiddleware
{
    public function handle(StateContract $state, NodeInterface $node, \Closure $next): StateContract
    {
        $start = microtime(true);
        
        try {
            $result = $next($state);
            
            $duration = microtime(true) - $start;
            
            // Send metrics
            Metrics::timing('node.execution', $duration, [
                'node' => $node->getName(),
                'status' => 'success',
            ]);
            
            return $result;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $start;
            
            Metrics::timing('node.execution', $duration, [
                'node' => $node->getName(),
                'status' => 'error',
            ]);
            
            throw $e;
        }
    }
}
```

Add to config:

```php
'middleware' => [
    'global' => [
        \App\Middleware\MetricsMiddleware::class,
    ],
],
```

### Circuit Breaker Middleware

Prevents cascading failures:

```php
// config/agenticgraph.php
'circuit_breaker' => [
    'failure_threshold' => 5,      // Open circuit after 5 failures
    'timeout' => 60,                // Track failures for 60 seconds
    'retry_after' => 300,           // Retry after 5 minutes
],

'middleware' => [
    'global' => [
        \Shahzad\AgenticGraph\Middleware\CircuitBreakerMiddleware::class,
    ],
],
```

When a node fails repeatedly, the circuit breaker opens and prevents further attempts for the configured duration.

### Caching Middleware

Cache node results:

```php
'middleware' => [
    'global' => [
        \Shahzad\AgenticGraph\Middleware\CachingMiddleware::class,
    ],
    'cache_ttl' => 3600, // Cache for 1 hour
],
```

Cache keys include:
- Node name
- Node file modification time (auto-invalidates on code changes)
- State hash

### Per-Node Middleware

Apply middleware to specific nodes:

```php
class ExpensiveNode extends AbstractNode
{
    // This node should be cached
    public function isCacheable(): bool
    {
        return true;
    }
    
    public function execute(StateContract $state): StateContract
    {
        // Expensive operation
        return $state;
    }
}
```


## CLI Commands

### List Registered Graphs

```bash
php artisan agenticgraph:list
```

Output:
```
Registered Graphs:
- customer-support (Customer support workflow)
- data-pipeline (Data processing pipeline)
- approval-workflow (Multi-step approval process)
```

### Visualize Graph

```bash
# Display in terminal
php artisan agenticgraph:visualize customer-support

# Save to file
php artisan agenticgraph:visualize customer-support --output=graph.md
```

### Execute Graph

```bash
# With empty state
php artisan agenticgraph:execute customer-support

# With JSON state
php artisan agenticgraph:execute customer-support --state='{"user_id":123,"message":"Help"}'

# With state from file
php artisan agenticgraph:execute customer-support --state="$(cat state.json)"
```

### List Checkpoints

```bash
# List all checkpoints for a graph
php artisan agenticgraph:checkpoint:list --graph=customer-support

# Limit results
php artisan agenticgraph:checkpoint:list --graph=customer-support --limit=10
```

Output:
```
Checkpoints for customer-support:
ID: ckpt_abc123 | Node: classify | Created: 2025-11-17 10:30:00
ID: ckpt_def456 | Node: respond  | Created: 2025-11-17 10:29:45
```

### Resume from Checkpoint

```bash
php artisan agenticgraph:checkpoint:resume ckpt_abc123
```

### Delete Checkpoint

```bash
php artisan agenticgraph:checkpoint:delete ckpt_abc123
```

### Cleanup Old Checkpoints

```bash
# Delete checkpoints older than 30 days (default)
php artisan agenticgraph:checkpoint:cleanup

# Custom retention period
php artisan agenticgraph:checkpoint:cleanup --days=7
```

### List Executions

```bash
# List all executions
php artisan agenticgraph:executions

# Filter by graph
php artisan agenticgraph:executions --graph=customer-support

# Filter by status
php artisan agenticgraph:executions --status=completed

# Limit results
php artisan agenticgraph:executions --limit=50
```

Output:
```
Recent Executions:
ID: exec_123 | Graph: customer-support | Status: completed | Duration: 2.5s
ID: exec_124 | Graph: data-pipeline   | Status: running   | Duration: -
ID: exec_125 | Graph: customer-support | Status: failed    | Duration: 1.2s
```

---

## Configuration

### Complete Configuration Reference

```php
<?php
// config/agenticgraph.php

return [
    // Storage driver: 'database', 'redis', or 'file'
    'storage' => env('AGENTICGRAPH_STORAGE', 'database'),

    // Checkpoint configuration
    'checkpoint' => [
        'enabled' => env('AGENTICGRAPH_CHECKPOINT_ENABLED', true),
        'strategy' => env('AGENTICGRAPH_CHECKPOINT_STRATEGY', 'every_node'),
        'ttl' => env('AGENTICGRAPH_CHECKPOINT_TTL', 7), // days
        'compression' => env('AGENTICGRAPH_CHECKPOINT_COMPRESSION', true),
        'encryption' => env('AGENTICGRAPH_CHECKPOINT_ENCRYPTION', false),
    ],

    // Execution configuration
    'execution' => [
        'timeout' => env('AGENTICGRAPH_TIMEOUT', 300), // seconds
        'max_retries' => env('AGENTICGRAPH_MAX_RETRIES', 3),
        'retry_delay' => env('AGENTICGRAPH_RETRY_DELAY', 1000), // milliseconds
        'parallel' => env('AGENTICGRAPH_PARALLEL', true),
        'queue_connection' => env('AGENTICGRAPH_QUEUE_CONNECTION', 'redis'),
        'queue_name' => env('AGENTICGRAPH_QUEUE_NAME', 'agenticgraph'),
        'max_iterations' => env('AGENTICGRAPH_MAX_ITERATIONS', 1000),
    ],

    // Distributed locking
    'lock' => [
        'driver' => env('AGENTICGRAPH_LOCK_DRIVER', 'redis'),
        'timeout' => env('AGENTICGRAPH_LOCK_TIMEOUT', 10), // seconds
        'prefix' => env('AGENTICGRAPH_LOCK_PREFIX', 'agenticgraph'),
    ],

    // PrismPHP integration
    'prism' => [
        'model' => env('AGENTICGRAPH_PRISM_MODEL', 'anthropic:claude-sonnet-4'),
        'cache_enabled' => env('AGENTICGRAPH_PRISM_CACHE', true),
        'cache_ttl' => env('AGENTICGRAPH_PRISM_CACHE_TTL', 60), // minutes
        'track_tokens' => env('AGENTICGRAPH_PRISM_TRACK_TOKENS', true),
        'timeout' => env('AGENTICGRAPH_PRISM_TIMEOUT', 120), // seconds
    ],

    // Events
    'events' => [
        'enabled' => env('AGENTICGRAPH_EVENTS_ENABLED', true),
        'listeners' => [],
    ],

    // Middleware
    'middleware' => [
        'global' => [
            \Shahzad\AgenticGraph\Middleware\LoggingMiddleware::class,
            \Shahzad\AgenticGraph\Middleware\TimingMiddleware::class,
            \Shahzad\AgenticGraph\Middleware\ValidationMiddleware::class,
        ],
        'per_node' => [],
        'cache_ttl' => env('AGENTICGRAPH_MIDDLEWARE_CACHE_TTL', 3600),
    ],

    // Circuit breaker
    'circuit_breaker' => [
        'failure_threshold' => env('AGENTICGRAPH_CIRCUIT_BREAKER_THRESHOLD', 5),
        'timeout' => env('AGENTICGRAPH_CIRCUIT_BREAKER_TIMEOUT', 60),
        'retry_after' => env('AGENTICGRAPH_CIRCUIT_BREAKER_RETRY_AFTER', 300),
    ],

    // Visualization
    'visualization' => [
        'enabled' => env('AGENTICGRAPH_VISUALIZATION', true),
        'format' => env('AGENTICGRAPH_VIZ_FORMAT', 'mermaid'),
    ],

    // Logging
    'logging' => [
        'channel' => env('AGENTICGRAPH_LOG_CHANNEL', 'stack'),
        'level' => env('AGENTICGRAPH_LOG_LEVEL', 'info'),
        'log_state' => env('AGENTICGRAPH_LOG_STATE', false),
    ],
];
```

### Environment Variables

```env
# Storage
AGENTICGRAPH_STORAGE=database

# Checkpoints
AGENTICGRAPH_CHECKPOINT_ENABLED=true
AGENTICGRAPH_CHECKPOINT_STRATEGY=every_node
AGENTICGRAPH_CHECKPOINT_TTL=7

# Execution
AGENTICGRAPH_TIMEOUT=300
AGENTICGRAPH_MAX_RETRIES=3
AGENTICGRAPH_MAX_ITERATIONS=1000
AGENTICGRAPH_QUEUE_CONNECTION=redis
AGENTICGRAPH_QUEUE_NAME=agenticgraph

# Locking
AGENTICGRAPH_LOCK_DRIVER=redis
AGENTICGRAPH_LOCK_TIMEOUT=10

# Prism
AGENTICGRAPH_PRISM_MODEL=anthropic:claude-sonnet-4
AGENTICGRAPH_PRISM_CACHE=true
AGENTICGRAPH_PRISM_CACHE_TTL=60
AGENTICGRAPH_PRISM_TRACK_TOKENS=true

# Circuit Breaker
AGENTICGRAPH_CIRCUIT_BREAKER_THRESHOLD=5
AGENTICGRAPH_CIRCUIT_BREAKER_TIMEOUT=60
AGENTICGRAPH_CIRCUIT_BREAKER_RETRY_AFTER=300

# Logging
AGENTICGRAPH_LOG_CHANNEL=stack
AGENTICGRAPH_LOG_LEVEL=info
AGENTICGRAPH_LOG_STATE=false
```


## Testing

### Testing Nodes

```php
<?php

use Tests\TestCase;
use Shahzad\AgenticGraph\State\State;
use App\Nodes\GreetCustomerNode;

class GreetCustomerNodeTest extends TestCase
{
    public function test_greets_customer_with_name()
    {
        $node = new GreetCustomerNode();
        
        $state = new State(['customer_name' => 'John Doe']);
        $result = $node->execute($state);
        
        $this->assertEquals(
            'Hello John Doe! How can I help you today?',
            $result->get('greeting')
        );
    }
    
    public function test_uses_default_name_when_missing()
    {
        $node = new GreetCustomerNode();
        
        $state = new State([]);
        $result = $node->execute($state);
        
        $this->assertStringContains Customer('Customer', $result->get('greeting'));
    }
}
```

### Testing Graphs

```php
<?php

use Tests\TestCase;
use Shahzad\AgenticGraph\Execution\GraphExecutor;
use Shahzad\AgenticGraph\State\State;
use App\Graphs\CustomerSupportGraph;

class CustomerSupportGraphTest extends TestCase
{
    public function test_complete_workflow()
    {
        $graph = CustomerSupportGraph::build();
        $executor = app(GraphExecutor::class);
        
        $initialState = new State([
            'customer_name' => 'Jane',
            'message' => 'I need a refund',
        ]);
        
        $result = $executor->execute($graph, $initialState);
        
        $this->assertEquals('billing', $result->get('intent'));
        $this->assertNotNull($result->get('response'));
    }
}
```

### Mocking Prism Responses

```php
<?php

use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Prism\Prism\ValueObjects\Meta;

class PrismNodeTest extends TestCase
{
    public function test_prism_node_with_fake()
    {
        // Mock Prism response
        $fake = TextResponseFake::make()
            ->withText('This is a test response')
            ->withUsage(new Usage(10, 20))
            ->withMeta(new Meta('test-id', 'test-model'));
        
        Prism::fake([$fake]);
        
        $node = new SummarizeNode();
        $state = new State(['article_text' => 'Long article...']);
        
        $result = $node->execute($state);
        
        $this->assertEquals('This is a test response', $result->get('summary'));
    }
}
```

### Testing with Checkpoints

```php
public function test_checkpoint_and_resume()
{
    $graph = CustomerSupportGraph::build();
    $executor = app(GraphExecutor::class);
    
    $initialState = new State(['customer_name' => 'Test']);
    
    // Execute until condition
    $result = $executor->executeUntil($graph, $initialState, function($state) {
        return $state->has('intent');
    });
    
    $checkpointId = $result['checkpointId'];
    $this->assertNotNull($checkpointId);
    
    // Resume execution
    $finalState = $executor->resume($checkpointId);
    $this->assertNotNull($finalState->get('response'));
}
```

### Testing Parallel Execution

```php
public function test_parallel_execution()
{
    Bus::fake();
    
    $graph = ParallelGraph::build();
    $executor = app(GraphExecutor::class);
    
    $result = $executor->execute($graph, new State(), useBatch: true);
    
    // Verify batch was dispatched
    Bus::assertBatched(fn($batch) => $batch->jobs->count() === 3);
}
```


## Best Practices

### 1. Keep Nodes Small and Focused

✅ **Good**: Single responsibility
```php
class ValidateEmailNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $email = $state->get('email');
        $isValid = filter_var($email, FILTER_VALIDATE_EMAIL);
        return $state->set('email_valid', $isValid);
    }
}
```

❌ **Bad**: Multiple responsibilities
```php
class ProcessUserNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        // Validates, saves to DB, sends email, updates cache...
        // Too much in one node!
    }
}
```

### 2. Use Immutable State

✅ **Good**: Return new state
```php
public function execute(StateContract $state): StateContract
{
    return $state->set('processed', true);
}
```

❌ **Bad**: Mutating state
```php
public function execute(StateContract $state): StateContract
{
    $state->data['processed'] = true; // Don't do this!
    return $state;
}
```

### 3. Handle Errors Gracefully

✅ **Good**: Proper error handling
```php
public function execute(StateContract $state): StateContract
{
    try {
        $result = $this->externalApi->call();
        return $state->set('result', $result);
    } catch (ApiException $e) {
        Log::error('API call failed', ['error' => $e->getMessage()]);
        return $state->set('error', $e->getMessage())
                     ->set('status', 'failed');
    }
}
```

### 4. Use Custom State Classes for Complex Data

✅ **Good**: Type-safe state with validation
```php
class OrderState extends State
{
    protected function rules(): array
    {
        return [
            'order_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'total' => 'required|numeric|min:0',
        ];
    }
}
```

### 5. Register Graphs in Service Provider

✅ **Good**: Centralized registration
```php
class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        GraphRegistry::register('order-processing', OrderGraph::build());
        GraphRegistry::register('user-onboarding', OnboardingGraph::build());
    }
}
```

### 6. Use Descriptive Node Names

✅ **Good**: Clear purpose
```php
class ValidatePaymentMethodNode extends AbstractNode
{
    protected string $name = 'Validate Payment Method';
}
```

❌ **Bad**: Vague names
```php
class ProcessNode extends AbstractNode {} // Process what?
```

### 7. Leverage Middleware for Cross-Cutting Concerns

✅ **Good**: Use middleware for logging, metrics, etc.
```php
'middleware' => [
    'global' => [
        LoggingMiddleware::class,
        MetricsMiddleware::class,
        CircuitBreakerMiddleware::class,
    ],
],
```

### 8. Set Appropriate Timeouts and Retries

✅ **Good**: Configure per node
```php
class ExternalApiNode extends AbstractNode
{
    public function getTimeout(): ?int
    {
        return 30; // 30 seconds for API calls
    }
    
    public function getRetryAttempts(): int
    {
        return 5; // Retry up to 5 times
    }
    
    public function shouldRetry(\Throwable $exception): bool
    {
        // Only retry on network errors
        return $exception instanceof NetworkException;
    }
}
```

### 9. Use Checkpoints for Long-Running Workflows

✅ **Good**: Enable checkpoints for resumability
```php
'checkpoint' => [
    'enabled' => true,
    'strategy' => 'every_node', // or 'conditional' for decision points
],
```

### 10. Monitor and Log Execution

✅ **Good**: Track metrics and errors
```php
Event::listen(GraphExecutionCompleted::class, function ($event) {
    Metrics::timing('graph.duration', $event->duration, [
        'graph' => $event->graphName,
    ]);
    
    if ($event->duration > 60) {
        Log::warning('Slow graph execution', [
            'graph' => $event->graphName,
            'duration' => $event->duration,
        ]);
    }
});
```


## Troubleshooting

### Common Issues

#### Issue: "Graph not found"

**Cause**: Graph not registered

**Solution**:
```php
// In AppServiceProvider::boot()
GraphRegistry::register('my-graph', MyGraph::build());

// Verify registration
php artisan agenticgraph:list
```

#### Issue: "Lock timeout"

**Cause**: Another process is holding the lock

**Solution**:
```env
# Increase lock timeout
AGENTICGRAPH_LOCK_TIMEOUT=30

# Or check for stuck executions
php artisan agenticgraph:executions --status=running
```

#### Issue: "Maximum iterations exceeded"

**Cause**: Circular graph or infinite loop

**Solution**:
```php
// Check graph for circular edges
php artisan agenticgraph:visualize my-graph

// Increase limit if legitimate
AGENTICGRAPH_MAX_ITERATIONS=2000
```

#### Issue: "Checkpoint not found"

**Cause**: Checkpoint expired or deleted

**Solution**:
```env
# Increase checkpoint TTL
AGENTICGRAPH_CHECKPOINT_TTL=30

# Check cleanup schedule
php artisan agenticgraph:checkpoint:list --graph=my-graph
```

#### Issue: "Memory exhausted"

**Cause**: Large state or memory leak

**Solution**:
```php
// 1. Reduce state size
$state = $state->forget('large_data');

// 2. Enable compression
AGENTICGRAPH_CHECKPOINT_COMPRESSION=true

// 3. Increase PHP memory limit
ini_set('memory_limit', '512M');
```

#### Issue: "Queue jobs not processing"

**Cause**: Queue worker not running

**Solution**:
```bash
# Start queue worker
php artisan queue:work redis --queue=agenticgraph

# Or use Supervisor/Horizon
php artisan horizon
```

#### Issue: "Circuit breaker opened"

**Cause**: Too many node failures

**Solution**:
```php
// 1. Check logs for root cause
tail -f storage/logs/laravel.log | grep "circuit"

// 2. Increase threshold
AGENTICGRAPH_CIRCUIT_BREAKER_THRESHOLD=10

// 3. Or temporarily disable
// Remove CircuitBreakerMiddleware from config
```

### Debugging Tips

#### Enable Debug Logging

```env
AGENTICGRAPH_LOG_LEVEL=debug
AGENTICGRAPH_LOG_STATE=true
```

#### Visualize Graph Structure

```bash
php artisan agenticgraph:visualize my-graph --output=debug.md
```

#### Check Execution History

```bash
php artisan agenticgraph:executions --graph=my-graph --limit=50
```

#### Inspect Checkpoints

```php
use Shahzad\AgenticGraph\Storage\DatabaseStorage;

$storage = new DatabaseStorage();
$checkpoint = $storage->load($checkpointId);

dd($checkpoint->state->toArray());
```

#### Monitor Queue

```bash
# Check queue size
php artisan queue:monitor redis:agenticgraph

# Check failed jobs
php artisan queue:failed
```

### Performance Optimization

#### 1. Use Database Indexes

Ensure migrations have been run:
```bash
php artisan migrate
```

#### 2. Enable Caching

```php
'middleware' => [
    'global' => [
        CachingMiddleware::class,
    ],
],
```

#### 3. Use Redis for Locks

```env
AGENTICGRAPH_LOCK_DRIVER=redis
```

#### 4. Optimize Checkpoint Strategy

```env
# Only checkpoint at decision points
AGENTICGRAPH_CHECKPOINT_STRATEGY=conditional
```

#### 5. Use Parallel Execution

```php
// Router returns array for parallel execution
return ['node_a', 'node_b', 'node_c'];
```


## API Reference

### GraphBuilder

```php
GraphBuilder::create(string $name): GraphBuilder
->description(string $description): self
->addNode(string $name, string $class): self
->addEdge(string $source, string $target, array $metadata = []): self
->addConditionalEdge(string $source, RouterInterface|string $router, array $metadata = []): self
->addJoin(array $sources, string $target): self
->setEntryPoint(string $name): self
->addExitPoint(string $name): self
->withMetadata(string $key, mixed $value): self
->build(): Graph
```

### Graph

```php
$graph->name: string
$graph->description: string
$graph->entryPoint: string
$graph->exitPoints: array
$graph->nodes: array
$graph->edges: array
$graph->metadata: array

$graph->getNode(string $name): NodeInterface
$graph->getEdgesFrom(string $nodeName): array
$graph->hasNode(string $name): bool
$graph->validate(): bool
$graph->toArray(): array
$graph->visualize(): string
```

### GraphRegistry

```php
GraphRegistry::register(string $name, Graph $graph): void
GraphRegistry::get(string $name): ?Graph
GraphRegistry::all(): array
GraphRegistry::unregister(string $name): void
GraphRegistry::clear(): void
GraphRegistry::count(): int
GraphRegistry::has(string $name): bool
```

### GraphExecutor

```php
$executor->execute(Graph $graph, StateContract $state, bool $useBatch = false): StateContract
$executor->executeAsync(Graph $graph, StateContract $state): string
$executor->resume(string $checkpointId): ?StateContract
$executor->executeUntil(Graph $graph, StateContract $state, callable $predicate): array
$executor->executeFromNode(Graph $graph, StateContract $state, string $startNode, string $executionId, bool $useBatch = false): StateContract
```

### State

```php
new State(array $data = [], array $metadata = [])

$state->get(string $key, mixed $default = null): mixed
$state->set(string $key, mixed $value): static
$state->has(string $key): bool
$state->forget(string $key): static
$state->all(): array
$state->toArray(): array
$state->merge(StateContract $other): static
$state->validate(): bool
$state->getMetadata(): array
State::fromArray(array $data): static
```

### NodeInterface

```php
$node->execute(StateContract $state): StateContract
$node->getName(): string
$node->getDescription(): ?string
$node->getTimeout(): ?int
$node->getRetryAttempts(): int
$node->getRetryDelay(): int
$node->shouldRetry(\Throwable $exception): bool
$node->getTags(): array
$node->onBefore(StateContract $state): void
$node->onAfter(StateContract $state, StateContract $result): void
$node->onError(\Throwable $exception, StateContract $state): void
```

### PrismNode

```php
$node->prompt(string $template, array $variables = []): string
$node->structured(string $prompt, mixed $schema): mixed
$node->tools(string $prompt, array $tools): mixed
$node->withModel(string $model): static
$node->withCache(int $ttlMinutes): static
$node->withoutCache(): static
```

### RouterInterface

```php
$router->route(StateContract $state): string|array
$router->getAvailableRoutes(): array
$router->getDefaultRoute(): ?string
$router->validateRoute(string $route): bool
```

### StorageInterface

```php
$storage->save(Checkpoint $checkpoint): string
$storage->load(string $id): ?Checkpoint
$storage->list(string $graphName, int $limit = 50): array
$storage->delete(string $id): bool
$storage->cleanup(int $olderThanDays = 30): int
```

### Facade

```php
use Shahzad\AgenticGraph\Facades\AgenticGraph;

AgenticGraph::create(string $name): GraphBuilder
AgenticGraph::register(string $name, Graph $graph): void
AgenticGraph::get(string $name): ?Graph
AgenticGraph::execute(Graph $graph, StateContract $state): StateContract
AgenticGraph::executeAsync(Graph $graph, StateContract $state): string
AgenticGraph::resume(string $checkpointId): ?StateContract
AgenticGraph::executeUntil(Graph $graph, StateContract $state, callable $predicate): array
```


## Examples

### Example 1: Simple Linear Workflow

```php
<?php

namespace App\Graphs;

use Shahzad\AgenticGraph\Graph\GraphBuilder;
use Shahzad\AgenticGraph\Nodes\AbstractNode;
use Shahzad\AgenticGraph\Contracts\StateContract;

class DataProcessingGraph
{
    public static function build()
    {
        return GraphBuilder::create('data-processing')
            ->addNode('fetch', FetchDataNode::class)
            ->addNode('transform', TransformDataNode::class)
            ->addNode('validate', ValidateDataNode::class)
            ->addNode('save', SaveDataNode::class)
            ->addEdge('fetch', 'transform')
            ->addEdge('transform', 'validate')
            ->addEdge('validate', 'save')
            ->setEntryPoint('fetch')
            ->addExitPoint('save')
            ->build();
    }
}

class FetchDataNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $data = Http::get('https://api.example.com/data')->json();
        return $state->set('raw_data', $data);
    }
}

class TransformDataNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $raw = $state->get('raw_data');
        $transformed = array_map(fn($item) => [
            'id' => $item['id'],
            'name' => strtoupper($item['name']),
        ], $raw);
        return $state->set('transformed_data', $transformed);
    }
}
```

### Example 2: Conditional Routing

```php
<?php

class ContentModerationGraph
{
    public static function build()
    {
        return GraphBuilder::create('content-moderation')
            ->addNode('analyze', AnalyzeContentNode::class)
            ->addNode('approve', ApproveNode::class)
            ->addNode('review', HumanReviewNode::class)
            ->addNode('reject', RejectNode::class)
            ->addEdge('analyze', 'approve')
            ->addConditionalEdge('analyze', ContentRouter::class)
            ->setEntryPoint('analyze')
            ->addExitPoint('approve')
            ->addExitPoint('reject')
            ->build();
    }
}

class ContentRouter extends AbstractRouter
{
    public function __construct()
    {
        $this->routes = ['approve', 'review', 'reject'];
        $this->defaultRoute = 'review';
    }
    
    public function route(StateContract $state): string|array
    {
        $score = $state->get('safety_score');
        
        if ($score >= 0.9) {
            return 'approve';
        } elseif ($score >= 0.5) {
            return 'review';
        } else {
            return 'reject';
        }
    }
}
```

### Example 3: Parallel Processing

```php
<?php

class ImageProcessingGraph
{
    public static function build()
    {
        return GraphBuilder::create('image-processing')
            ->addNode('upload', UploadNode::class)
            ->addNode('resize', ResizeNode::class)
            ->addNode('watermark', WatermarkNode::class)
            ->addNode('optimize', OptimizeNode::class)
            ->addNode('merge', MergeResultsNode::class)
            ->addNode('save', SaveNode::class)
            ->addEdge('upload', 'resize')
            ->addConditionalEdge('resize', new ParallelRouter(['watermark', 'optimize']))
            ->addJoin(['watermark', 'optimize'], 'merge')
            ->addEdge('merge', 'save')
            ->setEntryPoint('upload')
            ->addExitPoint('save')
            ->build();
    }
}

class ParallelRouter implements RouterInterface
{
    public function __construct(private array $targets) {}
    
    public function route(StateContract $state): string|array
    {
        return $this->targets; // Execute all in parallel
    }
    
    public function getAvailableRoutes(): array
    {
        return $this->targets;
    }
    
    public function getDefaultRoute(): ?string
    {
        return null;
    }
    
    public function validateRoute(string $route): bool
    {
        return in_array($route, $this->targets);
    }
}
```

### Example 4: AI-Powered Customer Support

```php
<?php

use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Routers\PrismRouter;

class AICustomerSupportGraph
{
    public static function build()
    {
        $router = new PrismRouter([
            'technical' => 'Technical issues, bugs, errors, system problems',
            'billing' => 'Payment, refunds, subscriptions, invoices',
            'account' => 'Login, password, profile, settings',
            'general' => 'General questions and other inquiries',
        ], defaultRoute: 'general');
        
        return GraphBuilder::create('ai-support')
            ->addNode('greet', GreetNode::class)
            ->addNode('classify', ClassifyNode::class)
            ->addNode('technical', TechnicalSupportNode::class)
            ->addNode('billing', BillingSupportNode::class)
            ->addNode('account', AccountSupportNode::class)
            ->addNode('general', GeneralSupportNode::class)
            ->addNode('respond', RespondNode::class)
            ->addEdge('greet', 'classify')
            ->addConditionalEdge('classify', $router)
            ->addEdge('technical', 'respond')
            ->addEdge('billing', 'respond')
            ->addEdge('account', 'respond')
            ->addEdge('general', 'respond')
            ->setEntryPoint('greet')
            ->addExitPoint('respond')
            ->build();
    }
}

class ClassifyNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $message = $state->get('message');
        
        $classification = $this->structured(
            "Classify this customer message: {$message}",
            [
                'category' => 'technical|billing|account|general',
                'urgency' => 'low|medium|high',
                'sentiment' => 'positive|neutral|negative',
            ]
        );
        
        return $state
            ->set('category', $classification['category'])
            ->set('urgency', $classification['urgency'])
            ->set('sentiment', $classification['sentiment']);
    }
}

class TechnicalSupportNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $message = $state->get('message');
        
        $response = $this->prompt(
            'Provide technical support for: {message}',
            ['message' => $message]
        );
        
        return $state->set('support_response', $response);
    }
}
```

### Example 5: Multi-Step Approval Workflow

```php
<?php

class ApprovalWorkflowGraph
{
    public static function build()
    {
        return GraphBuilder::create('approval-workflow')
            ->addNode('submit', SubmitRequestNode::class)
            ->addNode('manager_review', ManagerReviewNode::class)
            ->addNode('director_review', DirectorReviewNode::class)
            ->addNode('approved', ApprovedNode::class)
            ->addNode('rejected', RejectedNode::class)
            ->addEdge('submit', 'manager_review')
            ->addConditionalEdge('manager_review', function($state) {
                return $state->get('manager_approved') ? 'director_review' : 'rejected';
            })
            ->addConditionalEdge('director_review', function($state) {
                return $state->get('director_approved') ? 'approved' : 'rejected';
            })
            ->setEntryPoint('submit')
            ->addExitPoint('approved')
            ->addExitPoint('rejected')
            ->build();
    }
}

// Execute with human-in-the-loop
$result = AgenticGraph::executeUntil($graph, $initialState, function($state) {
    return $state->has('manager_approved');
});

// Wait for manager approval...
// Then resume
$finalState = AgenticGraph::resume($result['checkpointId']);
```

### Example 6: Data Pipeline with Error Handling

```php
<?php

class RobustDataPipelineGraph
{
    public static function build()
    {
        return GraphBuilder::create('robust-pipeline')
            ->addNode('extract', ExtractNode::class)
            ->addNode('transform', TransformNode::class)
            ->addNode('load', LoadNode::class)
            ->addNode('notify_success', SuccessNotificationNode::class)
            ->addNode('handle_error', ErrorHandlerNode::class)
            ->addEdge('extract', 'transform')
            ->addEdge('transform', 'load')
            ->addEdge('load', 'notify_success')
            ->setEntryPoint('extract')
            ->addExitPoint('notify_success')
            ->addExitPoint('handle_error')
            ->build();
    }
}

class ExtractNode extends AbstractNode
{
    public function getRetryAttempts(): int
    {
        return 5; // Retry up to 5 times
    }
    
    public function getRetryDelay(): int
    {
        return 2000; // 2 seconds between retries
    }
    
    public function shouldRetry(\Throwable $exception): bool
    {
        // Only retry on network errors
        return $exception instanceof \Illuminate\Http\Client\ConnectionException;
    }
    
    public function execute(StateContract $state): StateContract
    {
        try {
            $data = Http::timeout(30)->get('https://api.example.com/data')->json();
            return $state->set('extracted_data', $data)->set('status', 'success');
        } catch (\Throwable $e) {
            Log::error('Extract failed', ['error' => $e->getMessage()]);
            return $state->set('error', $e->getMessage())->set('status', 'failed');
        }
    }
}
```

---

## Additional Resources

### Links

- **GitHub Repository**: https://github.com/shahzad/agenticgraph
- **PrismPHP Documentation**: https://prism.echolabs.dev
- **Laravel Documentation**: https://laravel.com/docs

### Community

- Report issues on GitHub
- Contribute via pull requests
- Join discussions in GitHub Discussions

### License

MIT License - see LICENSE file for details

---

**Documentation Version**: 1.1.0  
**Last Updated**: November 17, 2025  
**Package Version**: 1.1.0

---

## Quick Reference Card

### Installation
```bash
composer require shahzad/agenticgraph
php artisan vendor:publish --provider="Shahzad\AgenticGraph\AgenticGraphServiceProvider"
php artisan migrate
```

### Create a Node
```php
class MyNode extends AbstractNode {
    public function execute(StateContract $state): StateContract {
        return $state->set('key', 'value');
    }
}
```

### Build a Graph
```php
$graph = GraphBuilder::create('my-graph')
    ->addNode('start', StartNode::class)
    ->addNode('end', EndNode::class)
    ->addEdge('start', 'end')
    ->setEntryPoint('start')
    ->addExitPoint('end')
    ->build();
```

### Register & Execute
```php
GraphRegistry::register('my-graph', $graph);
$result = AgenticGraph::execute($graph, new State(['input' => 'data']));
```

### CLI Commands
```bash
php artisan agenticgraph:list
php artisan agenticgraph:visualize my-graph
php artisan agenticgraph:execute my-graph --state='{}'
php artisan agenticgraph:checkpoint:list --graph=my-graph
```

---

**End of Documentation**


---

## Prompt Templates

### Overview

AgenticGraph provides a powerful prompt templating system inspired by LangChain, making it easy to create clean, reusable, and maintainable prompts for your AI-powered nodes.

### Why Use Prompt Templates?

**Before (Manual String Concatenation):**
```php
$prompt = "You are a helpful assistant.\n\n";
$prompt .= "User question: " . $userMessage . "\n\n";
$prompt .= "Product data: " . json_encode($products) . "\n\n";
$prompt .= "Instructions:\n";
$prompt .= "- Be helpful\n";
$prompt .= "- Be concise\n";
```

**After (Prompt Templates):**
```php
$template = PromptTemplate::create(
    <<<PROMPT
    You are a helpful assistant.
    
    User question: {question}
    Product data: {products}
    
    Instructions:
    - Be helpful
    - Be concise
    PROMPT
);

$response = $this->promptTemplate($template, [
    'question' => $userMessage,
    'products' => $products,
]);
```

### PromptTemplate Class

#### Basic Usage

```php
use Shahzad\AgenticGraph\Prompts\PromptTemplate;

$template = PromptTemplate::create('Hello {name}!');
$result = $template->format(['name' => 'World']);
// Output: "Hello World!"
```

#### In PrismNode

```php
use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Prompts\PromptTemplate;

class MyNode extends PrismNode
{
    protected PromptTemplate $template;
    
    public function __construct()
    {
        $this->template = PromptTemplate::create(
            'You are a {role}. Answer: {question}'
        );
    }
    
    public function execute(StateContract $state): StateContract
    {
        $response = $this->promptTemplate($this->template, [
            'role' => 'helpful assistant',
            'question' => $state->get('user_question'),
        ]);
        
        return $state->set('response', $response);
    }
}
```

#### Default Variables

```php
$template = PromptTemplate::create('Hello {name}, you are {age} years old')
    ->withDefaults([
        'name' => 'Guest',
        'age' => 'unknown',
    ]);

// Use defaults
$result = $template->format([]);
// Output: "Hello Guest, you are unknown years old"

// Override defaults
$result = $template->format(['name' => 'John', 'age' => 30]);
// Output: "Hello John, you are 30 years old"
```

#### Required Variables

```php
$template = PromptTemplate::create('Hello {name}!')
    ->withRequired(['name']);

// This will throw TemplateException
$template->format([]); // ❌ Missing required variable: name

// This works
$template->format(['name' => 'John']); // ✅
```

#### Inspecting Variables

```php
$template = PromptTemplate::create('Hello {name}, you have {count} messages');
$variables = $template->getVariables();
// Returns: ['name', 'count']
```

#### Loading from Files

```php
// File: storage/prompts/welcome.txt
// Content: "Welcome {name}! You have {count} new messages."

$template = PromptTemplate::fromFile(storage_path('prompts/welcome.txt'));
$result = $template->format(['name' => 'John', 'count' => 5]);
```

#### Automatic Formatting

Variables are automatically formatted based on type:

```php
$template = PromptTemplate::create('Data: {data}');

// Arrays → JSON
$template->format(['data' => ['key' => 'value']]);
// Output: "Data: {\n  \"key\": \"value\"\n}"

// Booleans → 'true'/'false'
$template->format(['data' => true]);
// Output: "Data: true"

// Null → empty string
$template->format(['data' => null]);
// Output: "Data: "
```

### ChatPromptTemplate Class

For structured conversations with system/user/assistant messages:

#### Basic Usage

```php
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;

$template = ChatPromptTemplate::create()
    ->system('You are a helpful assistant')
    ->user('Hello!')
    ->assistant('Hi there!')
    ->user('How are you?');

$messages = $template->format([]);
// Returns array of messages with roles
```

#### In PrismNode

```php
class ChatNode extends PrismNode
{
    protected ChatPromptTemplate $template;
    
    public function __construct()
    {
        $this->template = ChatPromptTemplate::create()
            ->system('You are an expert {domain} assistant')
            ->system('Always be helpful and accurate')
            ->user('Question: {question}');
    }
    
    public function execute(StateContract $state): StateContract
    {
        $response = $this->chatPrompt($this->template, [
            'domain' => $state->get('domain'),
            'question' => $state->get('question'),
        ]);
        
        return $state->set('response', $response);
    }
}
```

#### Few-Shot Learning

```php
$template = ChatPromptTemplate::create()
    ->system('Classify sentiment as positive, negative, or neutral')
    
    // Examples
    ->user('I love this product!')
    ->assistant('positive')
    
    ->user('This is terrible quality.')
    ->assistant('negative')
    
    ->user('The product arrived on time.')
    ->assistant('neutral')
    
    // Actual query
    ->user('Classify: {text}');

$result = $this->chatPrompt($template, ['text' => 'This is amazing!']);
```

#### Format as String

```php
$template = ChatPromptTemplate::create()
    ->system('You are helpful')
    ->user('Hello');

$string = $template->formatAsString();
// Output:
// SYSTEM: You are helpful
//
// USER: Hello
```

#### Create from Array

```php
$template = ChatPromptTemplate::fromArray([
    ['role' => 'system', 'content' => 'You are helpful'],
    ['role' => 'user', 'content' => 'Hello'],
    ['role' => 'assistant', 'content' => 'Hi!'],
]);
```

### Message Classes

#### SystemMessage

```php
use Shahzad\AgenticGraph\Prompts\Messages\SystemMessage;

$message = new SystemMessage('You are a helpful assistant');
$message = SystemMessage::create('You are a helpful assistant');

echo $message->getRole();    // 'system'
echo $message->getContent(); // 'You are a helpful assistant'
```

#### UserMessage

```php
use Shahzad\AgenticGraph\Prompts\Messages\UserMessage;

$message = new UserMessage('Hello!');
$message = UserMessage::create('Hello!');

echo $message->getRole();    // 'user'
echo $message->getContent(); // 'Hello!'
```

#### AssistantMessage

```php
use Shahzad\AgenticGraph\Prompts\Messages\AssistantMessage;

$message = new AssistantMessage('Hi there!');
$message = AssistantMessage::create('Hi there!');

echo $message->getRole();    // 'assistant'
echo $message->getContent(); // 'Hi there!'
```

### Reusable Template Library

Create a library of reusable templates:

```php
<?php

namespace App\Prompts;

use Shahzad\AgenticGraph\Prompts\PromptTemplate;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;

class EcommercePrompts
{
    public static function productRecommendation(): ChatPromptTemplate
    {
        return ChatPromptTemplate::create()
            ->system('You are an expert product recommendation assistant')
            ->system('Always base recommendations on actual product data')
            ->user('User preferences: {preferences}')
            ->user('Available products: {products}')
            ->user('Budget: {budget}')
            ->user('Recommend the best options and explain why');
    }
    
    public static function orderStatus(): PromptTemplate
    {
        return PromptTemplate::create(
            <<<PROMPT
            You are a customer service assistant.
            
            Order details: {order}
            User question: {question}
            
            Provide a clear, friendly update on the order status.
            PROMPT
        );
    }
    
    public static function contentModeration(): ChatPromptTemplate
    {
        return ChatPromptTemplate::create()
            ->system('You are a content moderation assistant')
            ->system('Analyze content for safety and policy compliance')
            ->user('Content: {content}')
            ->user('Provide a safety score (0-1) and explanation');
    }
}

// Usage
class RecommendationNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $template = EcommercePrompts::productRecommendation();
        
        $response = $this->chatPrompt($template, [
            'preferences' => $state->get('preferences'),
            'products' => $state->get('products'),
            'budget' => $state->get('budget'),
        ]);
        
        return $state->set('recommendations', $response);
    }
}
```

### Real-World Example: E-commerce Support

```php
<?php

namespace App\Nodes;

use Shahzad\AgenticGraph\Nodes\PrismNode;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Prompts\ChatPromptTemplate;

class FormatResponseNode extends PrismNode
{
    protected ChatPromptTemplate $template;
    
    public function __construct()
    {
        $this->template = ChatPromptTemplate::create()
            ->system('You are a helpful e-commerce customer support assistant')
            ->system('Instructions:
                - Present products in a friendly, helpful way
                - Highlight key features, ratings, and prices
                - Be enthusiastic but honest
                - Keep it concise but informative
                - ONLY mention products in the data - do NOT hallucinate')
            ->user('User question: {question}')
            ->user('Product data (JSON): {products}');
    }
    
    public function execute(StateContract $state): StateContract
    {
        $response = $this->chatPrompt($this->template, [
            'question' => $state->get('user_message'),
            'products' => $state->get('products'),
        ]);
        
        return $state->set('formatted_response', $response);
    }
}
```

### Best Practices

1. **Define templates in constructor** - Reuse the same template instance
2. **Use heredoc for multiline** - Cleaner than string concatenation
3. **Create template libraries** - Share templates across nodes
4. **Use required variables** - Catch missing data early
5. **Load from files** - Separate prompts from code for easier editing
6. **Use chat templates** - Better structure for conversational AI
7. **Add few-shot examples** - Improve LLM accuracy

### Error Handling

```php
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;

try {
    $template = PromptTemplate::create('Hello {name}!');
    $result = $template->format([]); // Missing 'name'
} catch (TemplateException $e) {
    echo $e->getMessage(); // "Missing template variable: name"
}
```

### Testing Templates

```php
use Shahzad\AgenticGraph\Prompts\PromptTemplate;

test('template formats correctly', function () {
    $template = PromptTemplate::create('Hello {name}!');
    $result = $template->format(['name' => 'World']);
    
    expect($result)->toBe('Hello World!');
});

test('template validates required variables', function () {
    $template = PromptTemplate::create('Hello {name}!')
        ->withRequired(['name']);
    
    $template->format([]); // Throws TemplateException
})->throws(TemplateException::class);
```
